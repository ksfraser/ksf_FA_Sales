# AGENTS.local — ksf_FA_Sales repo-local notes

Repo-specific operational notes for ksf_FA_Sales. The shared cross-module
facts/conventions live in the hardlinked `AGENTS.md` / `AGENTS_ARCH.md` — do not
edit those here; they are hardlinked and an in-place edit propagates to every
repo (canonical set under `~/Documents/`).

Repo-specific notes go ONLY in this file.

## History: this was a docs-only stub

Until commit `e3bcb90` this repo contained only `ProjectDcs/` + `_init/config`
and had **no `hooks.php` and no `src/`**. ISU's `ProcessingPipeline` was calling
`CREATE_SALES_INVOICE` into the void and reporting
"No module handles CREATE_SALES_INVOICE". The module now implements it.

## What this module is

Deliberately narrow: it owns exactly ONE capability, the `CREATE_SALES_INVOICE`
responder — raising a native FA sales invoice from staged order lines, so ISU
can orchestrate invoicing without owning FA's transactional mechanics.

Caller rule (same as CRM's CREATE_CUSTOMER and ksf_FA_Payment's
CREATE_PAYMENT): Square and WooCommerce MUST NOT call this. They stage orders
into ISU; ISU, after human review and duplicate-matching, is what requests the
invoice.

## Verified FA 2.4.3 API — read this before changing the service

Confirmed against `ksf_Infrastructure/FA/2.4.3`. All of the following were
initially wrong guesses, so do not "simplify" them back:

- **There is NO `class sales_order` in FA 2.4.x.** The class is `Cart`, in
  `sales/includes/cart_class.inc:24`. FA's own pages call it as lowercase
  `new cart(...)` because PHP class names are case-insensitive.
- **An unqualified `new Cart(...)` inside a namespaced file is a fatal.** It
  resolves to `Ksfraser\FA\Sales\Service\Cart`. It must be written `new \Cart`.
- API used, all in `cart_class.inc`:
  ```
  new \Cart($type, $trans_no = 0, $prepare_child = false)   // :93
  set_customer($customer_id, $customer_name, $currency, $discount, $payment, $cdiscount = 0)  // :341
  set_branch($branch_id, $tax_group_id, $tax_group_name, $phone, $email)   // :358
  add_to_cart($line_no, $stock_id, $qty, $price, $disc,
              $qty_done = 0, $standard_cost = 0, $description = null, ...) // :390
  write($policy = 0)                                        // :287
  ```
  `write()` opens and commits its OWN transaction and dispatches on trans_type,
  calling `write_sales_invoice($this)` for `ST_SALESINVOICE` (`:323`).
- Customer defaults: `get_customer($customer_id)`
  (`sales/includes/db/customers_db.inc:118`).
- Default branch: **`get_default_branch($customer_id)`**
  (`sales/includes/db/branches_db.inc:260`). There is NO
  `get_customer_branches()` in 2.4.3 — do not reach for it.
- Due date: `get_invoice_duedate($terms, $invdate)`
  (`sales/includes/db/sales_order_db.inc:384`).

## Why line prices are MANDATORY (judgement call, revisit only deliberately)

Invoice lines must carry the price the source system actually charged
(Square/Woo). Recomputing from FA's live price lists would silently rewrite the
imported history, and would drag in sales_type / price-list / tax-group /
discount chains to do it. So a line with no `price` is a **hard error**, not
something to guess at. There is no `get_price()` in 2.4.3 anyway — the price
chain is not a single call.

Accepted key spellings per line: `stock_id|stockid|stock|part_id`,
`quantity|qty`, `price|unit_price|rate`, `description|item_description`,
`discount`. Lines may be under `lines`, `items` or `line_items`.

## Linking an invoice to its sales order

If `source_order_no` (aliases `order_no`, `src_doc`) is supplied, the service
sets `cart->order_no` and `cart->src_docs`. Without that link the source sales
order never shows as invoiced. This matters because imported orders are
invoiced after the fact, for pick-and-ship.

## Namespace

`ksfraser\FrontAccounting\Sales\` (PSR-4 -> `src/FrontAccounting/Sales/`),
previously `Ksfraser\FA\Sales`. Module guideline: FA platform modules use
`ksfraser\FrontAccounting\<ModuleName>\`, not variations like `Ksfraser\FA<Module>`
or `Ksfraser\FA\<Module>`. Same rename applied in `ksf_FA_Customer`,
`ksf_FA_Payment` and `ksf_FA_CRM`.

This module is the single owner of `CREATE_SALES_INVOICE`, reached by ISU through
`hook_invoke('ksf_FA_Sales', 'CREATE_SALES_INVOICE', $data)` — never a direct
class call.

## PHP version constraint

Floor is PHP 7.3, container runs 7.4, so constructor property promotion (PHP
8.0) and `readonly` (PHP 8.1) are parse errors. `InvoiceDTO` uses classic
private-property + getter style for that reason. `composer.json` pins
`config.platform.php = 7.4.33`.

## Tests

`php vendor/bin/phpunit --testsuite Unit` — 12 tests / 40 assertions.

The `Cart` double in `tests/bootstrap.php` mirrors the real constructor and
method signatures deliberately, so a signature drift in the service is caught.
Negative cases covered: missing debtor, no lines, zero quantity, missing price,
cart `write()` returning nothing, and FA rejecting a line (which must abort the
whole invoice rather than write a partial one).
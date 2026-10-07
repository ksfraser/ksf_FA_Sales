<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Sales\Service;

use ksfraser\FrontAccounting\Sales\Entity\InvoiceDTO;

/**
 * InvoiceCreationService — writes a native FA sales invoice from staged lines.
 *
 * Native chokepoint is FA's own cart, not a hand-rolled INSERT. Verified
 * against FA 2.4.3:
 *   - `class Cart` in sales/includes/cart_class.inc, constructor
 *     `__construct($type, $trans_no = 0, $prepare_child = false)`.
 *   - `Cart::set_customer($customer_id, $customer_name, $currency, $discount,
 *     $payment, $cdiscount = 0)` (cart_class.inc:341)
 *   - `Cart::set_branch($branch_id, $tax_group_id, $tax_group_name, $phone,
 *     $email)` (cart_class.inc:358)
 *   - `Cart::add_to_cart($line_no, $stock_id, $qty, $price, $disc, $qty_done=0,
 *     $standard_cost=0, $description=null, $id=0, $src_no=0, $src_id=0)`
 *     (cart_class.inc:390)
 *   - `Cart::write($policy = 0)` (cart_class.inc:287) dispatches on trans_type
 *     and calls `write_sales_invoice($this)` for ST_SALESINVOICE
 *     (cart_class.inc:323), wrapping its own begin/commit transaction.
 *
 * PHP 7.3 compatible.
 *
 * @package ksf_FA_Sales
 * @since 1.0.0
 *
 * @BABOK Related: FR-SALES-001 (CREATE_SALES_INVOICE responder)
 */
class InvoiceCreationService
{
    /**
     * @param InvoiceDTO $dto
     * @return array
     * @throws \RuntimeException On validation failure or a failed core write
     */
    public function createInvoice(InvoiceDTO $dto)
    {
        if (!class_exists('Cart')) {
            throw new \RuntimeException(
                'FA core cart unavailable: Cart class not loaded '
                . '(sales/includes/cart_class.inc not included)'
            );
        }

        $debtorNo = $dto->getDebtorNo();
        if ($debtorNo <= 0) {
            throw new \RuntimeException('A positive debtor_no (customer_id) is required');
        }

        $lines = $this->normaliseLines($dto->getLines());
        if (count($lines) === 0) {
            throw new \RuntimeException('An invoice requires at least one line');
        }

        $customer = $this->loadCustomer($debtorNo);
        $branchCode = $dto->getBranchCode() > 0
            ? $dto->getBranchCode()
            : $this->firstBranch($debtorNo);

        $currency = $dto->getCurrency() !== '' ? $dto->getCurrency() : $customer['curr_code'];
        $documentDate = $this->toFaDate($dto->getDate());
        $dueDate = $dto->getDueDate() !== ''
            ? $this->toFaDate($dto->getDueDate())
            : $this->invoiceDueDate($customer['payment_terms'], $documentDate);

        // Leading backslash is required: an unqualified `new Cart` inside a namespaced
        // file would resolve to ksfraser\FrontAccounting\Sales\Service\Cart and fatal.
        $cart = new \Cart(ST_SALESINVOICE);
        $cart->trans_type = ST_SALESINVOICE;
        $cart->trans_no = 0;
        $cart->customer_id = $debtorNo;
        $cart->branch_id = $branchCode;
        $cart->document_date = $documentDate;
        $cart->due_date = $dueDate;
        $cart->reference = $dto->getReference();
        $cart->Comments = $dto->getMemo();

        $cart->set_customer(
            $debtorNo,
            $customer['name'],
            $currency,
            $customer['discount'],
            $customer['payment_terms']
        );
        $cart->set_branch($branchCode, 0, '', $customer['phone'], $customer['email']);

        $sourceOrderNo = $dto->getSourceOrderNo();
        if ($sourceOrderNo > 0) {
            // Link the invoice back to the sales order it was raised against,
            // which is what makes the order show as invoiced.
            $cart->order_no = $sourceOrderNo;
            $cart->src_docs = array($sourceOrderNo);
        }

        foreach ($lines as $index => $line) {
            $price = $line['price'] > 0
                ? $line['price']
                : $this->requirePrice($line['stock_id'], $line['quantity']);

            $added = $cart->add_to_cart(
                $index,
                $line['stock_id'],
                $line['quantity'],
                $price,
                $line['discount'],
                0,                  // qty_done
                0,                  // standard_cost
                $line['description']
            );

            if (!$added) {
                throw new \RuntimeException(
                    "FA rejected invoice line for stock_id '{$line['stock_id']}' "
                    . '(invalid stock code or empty description)'
                );
            }
        }

        // Cart::write() opens and commits its own transaction and returns the
        // new document number.
        $transNo = $cart->write();

        if (!$transNo) {
            throw new \RuntimeException('write_sales_invoice() did not return an invoice number');
        }

        $result = [
            'success' => true,
            'invoice_no' => (int)$transNo,
            'trans_type' => ST_SALESINVOICE,
            'debtor_no' => $debtorNo,
            'branch_code' => $branchCode,
            'line_count' => count($lines),
        ];

        if ($sourceOrderNo > 0) {
            $result['source_order_no'] = $sourceOrderNo;
        }

        return $result;
    }

    /**
     * Validate and coerce the incoming line array.
     *
     * @param array $lines
     * @return array
     * @throws \RuntimeException
     */
    private function normaliseLines($lines)
    {
        $clean = array();

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $stockId = '';
            foreach (array('stock_id', 'stockid', 'stock', 'part_id') as $key) {
                if (isset($line[$key]) && $line[$key] !== '') {
                    $stockId = (string)$line[$key];
                    break;
                }
            }

            $quantity = 0.0;
            foreach (array('quantity', 'qty', 'qty_done') as $key) {
                if (isset($line[$key]) && $line[$key] !== '') {
                    $quantity = (float)$line[$key];
                    break;
                }
            }

            // A line with neither a stock code nor a description is unusable.
            $description = '';
            foreach (array('description', 'item_description') as $key) {
                if (isset($line[$key])) {
                    $description = (string)$line[$key];
                    break;
                }
            }

            if ($stockId === '' && trim($description) === '') {
                continue;
            }

            if ($quantity <= 0) {
                throw new \RuntimeException(
                    'Invoice line quantity must be greater than zero'
                    . ($stockId !== '' ? " for stock_id '$stockId'" : '')
                );
            }

            $price = 0.0;
            foreach (array('price', 'unit_price', 'rate') as $key) {
                if (isset($line[$key]) && $line[$key] !== '') {
                    $price = (float)$line[$key];
                    break;
                }
            }

            $discount = 0.0;
            if (isset($line['discount']) && $line['discount'] !== '') {
                $discount = (float)$line['discount'];
            }

            $clean[] = array(
                'stock_id' => $stockId,
                'quantity' => $quantity,
                'price' => $price,
                'discount' => $discount,
                'description' => $description,
            );
        }

        return $clean;
    }

    /**
     * Load the debtor's defaults via FA's own accessor.
     *
     * @param int $debtorNo
     * @return array
     * @throws \RuntimeException
     */
    private function loadCustomer($debtorNo)
    {
        if (!function_exists('get_customer')) {
            throw new \RuntimeException(
                'FA core unavailable: get_customer() not loaded '
                . '(includes/db/customer_db.inc not included)'
            );
        }

        $customer = get_customer($debtorNo);
        if (!$customer) {
            throw new \RuntimeException("Debtor $debtorNo does not exist in FA");
        }

        return array(
            'name' => isset($customer['name']) ? $customer['name'] : '',
            'curr_code' => isset($customer['curr_code']) ? $customer['curr_code'] : '',
            'discount' => isset($customer['discount']) ? (float)$customer['discount'] : 0.0,
            'payment_terms' => isset($customer['payment_terms']) ? $customer['payment_terms'] : 1,
            'phone' => isset($customer['phone']) ? $customer['phone'] : '',
            'email' => isset($customer['email']) ? $customer['email'] : '',
        );
    }

    /**
     * @param int $debtorNo
     * @return int
     * @throws \RuntimeException
     */
    private function firstBranch($debtorNo)
    {
        // FA's own accessor for "the branch to use by default" — note there is
        // no get_customer_branches() in 2.4.3.
        if (!function_exists('get_default_branch')) {
            throw new \RuntimeException(
                'A branch_code is required: get_default_branch() unavailable'
            );
        }

        $branch = get_default_branch($debtorNo);
        if (!$branch || !isset($branch['branch_id'])) {
            throw new \RuntimeException(
                "Debtor $debtorNo has no sales branch; a branch_code is required"
            );
        }

        return (int)$branch['branch_id'];
    }

    /**
     * Resolve a unit price when the caller did not supply one.
     *
     * An imported invoice must reflect what the source system actually charged,
     * NOT what FA's live price lists would sell the item for today. Recomputing
     * would silently rewrite history (and would drag in sales_type, price
     * lists, tax group and discount chains). So a missing price is an error,
     * not something to guess at.
     *
     * @param string $stockId
     * @param float  $quantity
     * @return float
     * @throws \RuntimeException
     */
    private function requirePrice($stockId, $quantity)
    {
        throw new \RuntimeException(
            "Invoice line for stock_id '$stockId' (qty $quantity) has no price. "
            . 'An imported invoice must carry the price the source system charged; '
            . "supply 'price' (or 'unit_price'/'rate') on the line."
        );
    }

    /**
     * @param mixed  $paymentTerms
     * @param object $documentDate
     * @return object
     */
    private function invoiceDueDate($paymentTerms, $documentDate)
    {
        if (function_exists('get_invoice_duedate')) {
            return get_invoice_duedate($paymentTerms, $documentDate);
        }
        return $documentDate;
    }

    /**
     * @param string $value
     * @return object
     */
    private function toFaDate($value)
    {
        $time = strtotime((string)$value);
        if ($time === false) {
            $time = time();
        }
        return new \DateTime('@' . $time);
    }
}
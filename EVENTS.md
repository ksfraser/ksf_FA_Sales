# KSF FrontAccounting — Event Registry

Master list of all cross-module events in the KSF codebase. Each event is
verified against actual `hook_invoke_all()` / `hook_invoke_first()` calls
and corresponding listener methods in `hooks.php` files.

**Conventions:**
- `{object}_{action}` in snake_case — see `AGENTS_ARCH.md` §11.
- `hook_invoke_all` = broadcast (any module can listen); `hook_invoke_first` =
  first responder wins (return value to caller).
- Emitters fire *after* state is committed. Listeners must be fault-tolerant.

**Status legend:**
- **Active** — emitter + at least one listener verified in code.
- **Emitter-only** — emitter verified, no listener found in dev tree.
- **Listener-only** — listener exists but no emitter calls `hook_invoke_all`.
- **Planned** — designed in ProjectDcs/ docs only; no code yet.

---

## 1. Inventory & Item Events

### `item_created` — Active

New stock item written.

**Emitter:** `ksf_FA_Common` — `src/ItemEvents/ItemEventPublisher.php:167`
**Listeners:**
- `ksf_FA_Woocommerce` — `hooks.php:216` (sync to WC)
- `ksf_FA_Square` — `hooks.php:285` (sync to Square)

---

### `item_updated` — Active

Existing stock item changed.

**Emitter:** `ksf_FA_Common` — `src/ItemEvents/ItemEventPublisher.php:167`
**Listeners:**
- `ksf_FA_Woocommerce` — `hooks.php:221`
- `ksf_FA_Square` — `hooks.php:290`

---

### `pre_item_delete` / `post_item_write` — Active (FA_ProductAttributes)

Custom hooks patched into `items.php` by `FA_ProductAttributes/src/.../ItemsPhpTabHookPatcher.php`.
These are standard FA tab hooks, not broadcast — any module can register.

**Emitter:** patched `items.php` (FA_ProductAttributes installer)
**Listeners:** any module implementing the corresponding methods.

---

### `item_display_tab_headers` / `item_display_tab_content` — Active

Custom item tab UI hooks from FA_ProductAttributes.

---

### `ksf_crud_event` — Active (generic broadcast)

Generic CRUD lifecycle broadcast fired by `CrudEventEmitterTrait` alongside
specific events (`item_created`, etc.). Payload: `action`, `module`,
`record_type`, `record_id`, `data`.

**Emitter:** `ksf_FA_Common` — `src/Traits/CrudEventEmitterTrait.php:59`
**Listeners:** any module via `hook_invoke_all('ksf_crud_event', $data)`.

---

## 2. Stock Reservation Events

### `stock_reservation_insufficient` — Active

Reservation failed (stock below requested qty).

**Emitter:** `ksf_FA_StockReservations` — `src/.../SalesOrderReservationHandler.php:389`
**Listeners:**
- `ksf_FA_SuggestedPurchaseOrder` — `hooks.php:155`
- `ksf_FA_StockTurnover` — `hooks.php:128`

---

### `stock_turnover_data` — Active

Turnover metrics broadcast (nightly recalc).

**Emitter:** `ksf_FA_StockTurnover` — `hooks.php:116`
**Listeners:**
- `ksf_FA_StockTurnover` — `hooks.php:119` (self-consumer)
- `ksf_FA_ManufacturerConsolidation` — `hooks.php:121`

---

### `stock_reserved` — Emitter-only

Stock successfully reserved for an order.

**Emitter:** `ksf_FA_StockReservations` — `hooks.php:173`
**Listeners:** none found in dev tree.

---

### `stock_released` — Emitter-only

Reservation released (fulfilled, voided, cancelled).

**Emitter:** `ksf_FA_StockReservations` — `hooks.php:202`, `hooks.php:226`
**Listeners:** none found in dev tree.

---

### `stock_insufficient` — Emitter-only

Stock level below threshold during SO creation.

**Emitter:** `ksf_FA_StockReservations` — `hooks.php:261`
**Listeners:** none found in dev tree.

---

## 3. Purchase Order & Import Events

### `order_imported` — Active

External order (WC/Square) imported into FA.

**Emitters:**
- `ksf_FA_Woocommerce` — `src/.../OrderExporter.php:261`
- `ksf_FA_Square` — `src/Services/ImportService.php:566`

**Listeners:**
- `ksf_FA_ProjectManagement` — `hooks.php:277`
- `ksf_FA_HRM` — `hooks.php:493`

---

### `po_tracking_data` — Active

PO tracking metrics broadcast (nightly recalc).

**Emitter:** `ksf_FA_PurchaseOrderTracking` — `hooks.php:106`
**Listeners:**
- `ksf_FA_PurchaseOrderTracking` — `hooks.php:109` (self-consumer)
- `ksf_FA_ManufacturerConsolidation` — `hooks.php:130`

---

### `suggested_po_approved` — Listener-only

Listener exists but no emitter verified.

**Listeners:**
- `ksf_FA_ManufacturerConsolidation` — `hooks.php:112`

---

### `consolidation_data` — Emitter-only

Manufacturer consolidation metrics broadcast.

**Emitter:** `ksf_FA_ManufacturerConsolidation` — `hooks.php:109`
**Listeners:** none found.

---

### `consolidation_suggested` — Emitter-only

Consolidation recommendations generated.

**Emitter:** `ksf_FA_ManufacturerConsolidation` — `hooks.php:148`
**Listeners:** none found.

---

### `upgrade_module` — Active

Module upgrade notification (ksf_fa_downloader collects).

**Emitter:** any module calling `hook_invoke_all('upgrade_module', $data)`
**Listener:** `ksf_fa_downloader` — `hooks.php:121`

---

### Planned: `suggested_po_created`, `grn_received`, `po_created`

Designed in `ProjectDcs/Event-Driven Architecture.md` and per-module docs.
**No emitter code exists yet.** `ksf_FA_PurchaseOrderTracking` has listener
methods for `grn_received` and `po_created` (`hooks.php:118`, `hooks.php:124`)
but no module calls `hook_invoke_all('grn_received')` or
`hook_invoke_all('po_created')`.

---

## 4. Import Staging Pipeline

Hook-first request/response pattern for the ISP framework.

**Emitters:** `ksf_FA_ImportStagingProcessing` (`hooks.php`, `StagingService.php`,
`ProcessingPipeline.php`), `ksf_FA_Square` / `ksf_FA_Woocommerce`
(`IsuStagingGateway.php`)
**Listener:** `ksf_FA_ImportStagingProcessing_UI` (`hooks.php`)

| Event | Direction | Source |
|-------|-----------|--------|
| `SEARCH_CUSTOMER` | request | ISP `StagingService.php:462` |
| `GET_PAYMENT` | request | ISP `StagingService.php:507` |
| `CREATE_CUSTOMER` | request | ISP `ProcessingPipeline.php:401` |
| `CREATE_PAYMENT` | request | ISP `ProcessingPipeline.php:416` |
| `CREATE_SALES_INVOICE` | request | ISP `ProcessingPipeline.php:430` |
| `PROCESS_STAGING` | request | ISP `hooks.php:332` |
| `STAGE_CUSTOMER` | request | ISP `hooks.php:374` |
| `STAGE_TRANSACTION` | request | ISP `hooks.php:415` |
| `STAGE_PAYMENT` | request | ISP `hooks.php:457` |
| `STAGE_ENTITY` | request | ISP_UI → Square/Woocommerce `IsuStagingGateway.php` |
| `STAGING_EXISTS` | request | ISP_UI → Square `IsuStagingGateway.php:46` |

---

## 5. Calendar & Scheduling Events

### `calendar_entry_create` / `_update` / `_delete` / `_entries_query` — Active

Calendar CRUD via REST API.

**Emitters (hook_invoke_first):** `ksf_FA_API` — `src/.../CalendarController.php:153/193/225/76`
**Listeners:**
- `ksf_FA_Calendar` — `hooks.php:121/146/171/197`

---

### `calendar_register_source_types` / `_menu_items` — Active

Extension registration for calendar sources/menus.

**Emitter:** `ksf_FA_Common` — `src/ExtensionRegistry/ExtensionRegistry.php:62-63`
**Listeners:**
- `ksf_FA_Calendar` — `hooks.php:539` (collects registrations)

---

### `calendar_scheduling_context` — Emitter-only

Scheduling context broadcast.

**Emitter:** `ksf_FA_Calendar` — `src/.../SchedulingCalculator.php:34`
**Listeners:** none found.

---

### `calendar_invitee_contact_types` — Emitter-only

**Emitter:** `ksf_FA_Calendar` — `FA_Cal_Module.php:751`
**Listeners:** `ksf_FA_Calendar` `hooks.php:236` (self)

---

### `calendar_individual_status_changed` — Emitter-only

**Emitter:** `ksf_FA_Calendar` — `FA_Cal_Module.php:1127`
**Listeners:** none found.

---

### `calendar_billable_entry_completed` — Emitter-only

**Emitter:** `ksf_FA_Calendar` — `FA_Cal_Module.php:1325`
**Listeners:** none found.

---

### `mail_send_ical` — Active

iCal attachment dispatch.

**Emitter:** `ksf_FA_Calendar` — `cal_ical.php:481`
**Listener:** `ksf_FA_Mail` — `hooks.php:194/211`

---

### `reminder_dispatch_popup` / `_email` — Active

Reminder delivery (popup/email).

**Emitter:** any scheduled reminder trigger
**Listener:** `ksf_FA_Calendar` — `hooks.php:276` / `hooks.php:325`

---

### `reminder_delivery_methods` — Emitter-only (self-consumer)

**Emitter:** `ksf_FA_Calendar` — `FA_Cal_Module.php:1580`
**Listener:** `ksf_FA_Calendar` — `hooks.php:255` (self)

---

## 6. Project & Timesheet Events

Emitter-only group — all from `ksf_FA_Timesheets` (`TimesheetService.php`,
`TimesheetHooks.php`, `TimeEntryService.php`). No cross-module listeners
found in dev tree.

| Event | Source |
|-------|--------|
| `timesheet_submitted` | `TimesheetService.php:220`, `TimesheetHooks.php:45` |
| `timesheet_approved` | `TimesheetService.php:258`, `TimesheetHooks.php:61` |
| `timesheet_rejected` | `TimesheetHooks.php:63` |
| `timesheet_export_payroll` | `TimesheetService.php:259`, `TimesheetHooks.php:74` |
| `timesheet_check_auto_approve` | `TimesheetService.php:282` |
| `timesheet_get_week_config` | `TimesheetService.php:137` |
| `time_entry_added` | `TimesheetService.php:204` |
| `time_get_billing_rule` | `TimesheetHooks.php:85` (self-consumer: `TimeEntryService.php:91`) |
| `approval_request` | `TimesheetService.php:228` |
| `project_check_project_admin` | `TimesheetService.php:335` |
| `project_activity_validate` | `TimesheetService.php:170` |
| `project_stage_get_activities` | `TimesheetService.php:124` |
| `project_get_current_stage` | `TimesheetService.php:107` |
| `orgchart_get_reports` | `TimesheetService.php:88` |

---

## 7. Team & User Lifecycle Events

Emitter-only group.

| Event | Source |
|-------|--------|
| `team_created` | `ksf_FA_Teams` `hooks.php:181` |
| `team_updated` | `ksf_FA_Teams` `hooks.php:202` |
| `team_deleted` | `ksf_FA_Teams` `hooks.php:221` |
| `user_team_assigned` | `ksf_FA_Teams` `hooks.php:244` |
| `user_team_unassigned` | `ksf_FA_Teams` `hooks.php:265` |
| `user_provisioned` | `ksf_FA_RBAC` `hooks.php:213` |
| `user_updated` | `ksf_FA_RBAC` `hooks.php:234` |
| `user_deactivated` | `ksf_FA_RBAC` `hooks.php:253` |

---

## 8. Cross-Module Config Seam

### `ksf_get_value` / `ksf_get_values` / `ksf_set_value` — Active

Key-value config read/write via `HookQueryProviderTrait`.
`hook_invoke_first('ksf_get_value', $key)` returns first provider's result.
`hook_invoke_all` for set/values.

**Providers:** `ksf_FA_RBAC` (`hooks.php:259`), `ksf_FA_Mail` (`hooks.php:328`),
`ksf_FA_Common` (`HookQueryProviderTrait`)
**Consumers:** any module using the trait.

---

## 9. Authorization Events

### `authorize` — Active (hook_invoke_first)

CRUD access check. Returns `true`/`false`/`null`.

**Emitters:** `ksf_FA_CRM` (`reporting/rep_customer_*.php`),
`ksf_FA_ImportStagingProcessing` (`hooks.php:820`)
**Listener:** `ksf_FA_RBAC` — `hooks.php:337`

---

### `filterRecordList` — Active

Record-level list filtering.

**Emitter:** any module via `RbacGateway`
**Listener:** `ksf_FA_RBAC` — `hooks.php:465`

---

### `gpg_encrypt` — Active

GPG encryption dispatch.

**Emitter:** `ksf_GPG` — `src/Traits/GPGEncryptionTrait.php:284`
**Listener:** `ksf_FA_GPG`

---

### `gpg_register_portal_key` — Emitter-only

**Emitter:** `ksf_FA_GPG` — `pages/portal_key_register.php:46`,
`pages/ess_key_register.php:46`
**Listeners:** none found.

---

## 10. Logging

### `ksf_log` — Active

Centralized logging dispatch.

**Emitter:** `ksf_FA_Common` — `src/logging_functions.php:58`
**Listener:** `ksf_FA_Logging` — `hooks.php:74`

---

## 11. Attached to Dev Tree Only

Events from `ksf_FA_Users`, `ksf_FA_Contacts`, `ksf_FA_Employee`.
These modules exist only under `ksf_Infrastructure/fa_modules/` (deployed-only)
and have no dev tree source — events cannot be verified.

**Planned events per deployed-only modules:**
- `user_create` (ksf_FA_Users) — no emitter in dev tree
- `add_contact` / `contact_create` (ksf_FA_Contacts) — no emitter in dev tree

---

## Event Lifecycle Summary

| Event | Status | Emitter | Listener(s) |
|-------|--------|---------|-------------|
| `item_created` | **Active** | ksf_FA_Common | Woocommerce, Square |
| `item_updated` | **Active** | ksf_FA_Common | Woocommerce, Square |
| `ksf_crud_event` | **Active** | Traits/CrudEventEmitterTrait | (generic) |
| `stock_reservation_insufficient` | **Active** | StockReservations | SuggestedPO, StockTurnover |
| `stock_turnover_data` | **Active** | StockTurnover | StockTurnover, ManufacturerConsolidation |
| `stock_reserved` | Emitter-only | StockReservations | — |
| `stock_released` | Emitter-only | StockReservations | — |
| `stock_insufficient` | Emitter-only | StockReservations | — |
| `order_imported` | **Active** | Woocommerce, Square | ProjectManagement, HRM |
| `po_tracking_data` | **Active** | PurchaseOrderTracking | PurchaseOrderTracking, ManufacturerConsolidation |
| `upgrade_module` | **Active** | (any) | ksf_fa_downloader |
| `mail_send_ical` | **Active** | Calendar | Mail |
| `calendar_entry_*` | **Active** | ksf_FA_API | Calendar |
| `authorize` | **Active** | (various) | RBAC |
| `filterRecordList` | **Active** | (various) | RBAC |
| `ksf_get_value` | **Active** | (any) | RBAC, Mail, Traits |
| `ksf_set_value` | **Active** | (any) | RBAC, Traits |
| `ksf_log` | **Active** | ksf_FA_Common | Logging |
| `gpg_encrypt` | **Active** | ksf_GPG | GPG |
| `timesheet_*` | Emitter-only | Timesheets | — |
| `team_*` | Emitter-only | Teams | — |
| `user_*` (lifecycle) | Emitter-only | RBAC | — |
| `suggested_po_created` | Planned | — | — |
| `grn_received` | Planned | — | — |
| `po_created` | Planned | — | — |

---

*Document Version: 2.0.0 — verified against dev tree 2026-09-14*
*Maintained in: ~/Documents/EVENTS.md*
*Cross-reference: ProjectDcs/Event-Driven Architecture.md*

# BR-SALES-001 - Sales Module (Opportunities & Contracts)

## Business Requirement

**Module**: Sales (new)
**Status**: Proposed
**Integration**: Hook-based

### Scope

Owns:
- **Opportunities** (sales pipeline stages)
- **Contracts** (link to BR-FINANCE-001 e-invoicing)
- **Billing Rules** (cost, cost_plus, fixed_rate per contract/activity)
- **Opportunity-to-Project Conversion** (links to PM templates)

### Opportunity Pipeline

```
Lead → Qualified → Proposal → Negotiation → Closed (Won/Lost)
```

### Contract-Billing Integration

Contracts contain billing rules that are queried (not directly linked) at expense/time entry:

```php
// At entry time: only show available rules
hook_invoke_first('contract_get_billing_rules', [
    'contract_id' => $contractId,
    'activity_code' => $activityCode,
]);
// Returns: ['rule' => 'cost_plus', 'margin' => 0.15, 'is_billable' => true]

// At approval time: apply billing rules
hook_invoke_all('expense_approval_billing_applied', [
    'expense_report_id' => $reportId,
    'line_items' => [
        ['amount' => 150, 'rule_applied' => 'cost_plus', 'billable_amount' => 172.50],
    ],
    'direct_delivery_created' => true,  // Creates record for AR batching
]);
```

### Direct Delivery (Billing Batch Item)

Not inserted into `stock_master`. Separate billing batch table:

```sql
CREATE TABLE `0_billing_batch_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `batch_id` INT UNSIGNED NOT NULL,
  `source_type` ENUM('expense_line','time_entry') NOT NULL,
  `source_id` INT UNSIGNED NOT NULL,
  `project_id` INT UNSIGNED,
  `customer_id` INT UNSIGNED,
  `contract_id` INT UNSIGNED,
  `description` VARCHAR(255),
  `billable_amount` DECIMAL(15,2),
  `currency` CHAR(3),
  `status` ENUM('pending_batch','batched','invoiced') NOT NULL DEFAULT 'pending_batch',
  `invoice_id` INT UNSIGNED,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_batch` (`batch_id`),
  KEY `idx_customer` (`customer_id`)
);
```

Billing batch items created by `expense_approval_billing_applied` hook, then AR batches for invoicing.

# Data Model (Recurring Order Scheduled Execution)

**Feature**: 003-recurring-order-scheduled-execution  
**Date**: 2026-03-09  

No new tables or columns are required for the core fix (scheduling the job and passing notification/smart_settings). Existing entities and their relevant fields are documented below for implementation reference.

## Entities (existing)

### RecurringOrder

- **Table**: `recurring_orders`
- **Relevant attributes** (for this feature):
  - `id`, `branch_id`, `created_by`, `order_name`, `order_source_type`, `status`, `sourceable_type`, `sourceable_id`
  - `repeat_frequency`, `repeat_config`, `scheduling_time_am`, `scheduling_time_pm`
  - `start_date`, `end_date`, `end_type`
  - `next_run_at` (datetime) – when the next occurrence should run; used by ProcessRecurringOrdersJob to select due orders
  - `paused_at` – set when paused; job excludes rows where `paused_at` is not null
  - `notification_channels` (array), `notification_options` (array), `smart_settings` (array)
- **Status values**: `pending`, `generated`, `in_progress`, `paused`. List semantics:
  - **In progress list**: status in (`generated`, `in_progress`)
  - **Next scheduling list**: status = `pending`
  - **Paused list**: status = `paused`
- **Relations**: `items` (RecurringOrderItem), `sourceable` (Supplier or BranchManager), `purchaseOrders` (PurchaseOrder)

### RecurringOrderItem

- **Table**: `recurring_order_items`
- Used by the job to build line items for the created purchase order. No schema change.

### PurchaseOrder (Purchase module)

- **Table**: `purchase_orders`
- **Relevant for this feature**:
  - `recurring_order_id` – links to the recurring order
  - `notification_channels`, `message` – currently job passes hardcoded `['app']` and a fixed message; should pass recurring order’s `notification_channels` and optionally message
  - If supported: optional JSON (e.g. `recurring_metadata` or existing metadata) to store `notification_options` and `smart_settings` from the recurring order at creation time for downstream use
- **State**: Created by `PurchaseOrderService::createOrder()` from the job; no new status values.

## Validation / business rules (from spec)

- Only recurring orders with `status = pending`, `next_run_at <= now()`, `paused_at = null`, and within `end_date` (if set) are processed (already enforced in job).
- After creating a purchase order, the recurring order is updated to `status = generated` and `next_run_at` is set to the next occurrence (already implemented in RecurringOrderService::computeNextRunAtFromModel).

## Optional schema addition (if Purchase module has no place for recurring metadata)

- **Table**: `purchase_orders`
- **Column**: `recurring_metadata` (JSON, nullable). Contents: `{ "notification_options": [...], "smart_settings": [...] }` copied from the recurring order when the order is created from a recurring order. Allows downstream notification and smart-setting logic without changing recurring_orders.

If the Purchase module already has a metadata or similar column that can store this, use it instead of adding a new column.

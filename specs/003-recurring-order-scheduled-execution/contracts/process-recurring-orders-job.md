# Contract: ProcessRecurringOrdersJob

**Feature**: 003-recurring-order-scheduled-execution  
**Component**: `Modules\RecurringOrder\Jobs\ProcessRecurringOrdersJob`

## Purpose

The job is responsible for selecting recurring orders that are due (scheduled date/time reached) and creating the corresponding purchase orders, then updating the recurring order's next run time and status.

## Input (implicit: no parameters)

The job takes no constructor parameters. It queries the database for due recurring orders.

## Selection criteria (read contract)

The job MUST select recurring orders that satisfy ALL of:

- `status` = `pending`
- `next_run_at` IS NOT NULL AND `next_run_at` <= now (app timezone)
- `end_date` IS NULL OR `end_date` >= today
- `paused_at` IS NULL
- For `repeat_frequency` = `based_on_inventory`, still require `next_run_at` set (existing logic)
- Order by `next_run_at` ascending, limit 50

Relations to load: `items`, `sourceable`.

## Output (write contract)

For each selected recurring order, the job MUST:

1. **Create one purchase order** via `PurchaseOrderService::createOrder()` with:
   - Order type, branch, requested_by, sourceable, supplier_id (if direct), items (from recurring order items), status PENDING, message, `recurring_order_id`.
   - **notification_channels**: recurring order's `notification_channels` if present; otherwise default `['app']`.
   - **notification_options / smart_settings**: passed from recurring order (e.g. in payload or metadata) so they are applied per spec (FR-004, FR-005). If the purchase order model supports a metadata/recurring_metadata JSON column, store them there; otherwise pass in the create payload for the service to persist if supported.

2. **Update the recurring order**:
   - Set `status` = `generated`.
   - Set `next_run_at` = result of `RecurringOrderService::computeNextRunAtFromModel($recurringOrder->fresh())`.

## Error handling

- If processing one recurring order throws, the job MUST log the error (recurring_order_id, message, trace) and MUST continue with the remaining due orders (no single failure stops the batch).

## Scheduling contract

- The job MUST be scheduled to run at least every 5 minutes (e.g. `$schedule->job(ProcessRecurringOrdersJob::class)->everyFiveMinutes()`) so that due orders are processed within the agreed time window (spec SC-001).

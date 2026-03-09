# Quickstart: Recurring Order Scheduled Execution (003)

**Branch**: `003-recurring-order-scheduled-execution`  
**Goal**: Get recurring orders to run at their scheduled time and apply notification/smart settings.

## What was wrong

- **ProcessRecurringOrdersJob** was never scheduled, so due orders were never picked.
- The job hardcoded `notification_channels` to `['app']` and did not pass **notification_options** or **smart_settings** from the recurring order to the created purchase order.

## What to do (implementation)

1. **Schedule the job**  
   In `Modules/RecurringOrder/app/Providers/RecurringOrderServiceProvider.php`, in `registerCommandSchedules()`:
   - Uncomment the `booted` closure and add the recurring orders job.
   - Use `Illuminate\Console\Scheduling\Schedule` and run the job every 5 minutes:
     - `$schedule->job(\Modules\RecurringOrder\Jobs\ProcessRecurringOrdersJob::class)->everyFiveMinutes();`

2. **Production cron setup**  
   On the server, ensure the Laravel scheduler runs every minute so that `everyFiveMinutes()` and other schedule entries run on time. Add to crontab:
   ```bash
   * * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1
   ```
   Without this, no scheduled job (including recurring orders) will run. Use the same timezone as `APP_TIMEZONE` (or the server’s default) so that `next_run_at` and schedule times match.

3. **Job payload (notification and smart settings)**  
   In `ProcessRecurringOrdersJob::processOne()`:
   - Build the `createOrder` payload with:
     - `notification_channels`: from `$recurring->notification_channels`; if empty, use `['app']`.
     - If `PurchaseOrderService::createOrder()` (or the purchase_orders table) supports it: pass `notification_options` and `smart_settings` from the recurring order (e.g. as a `recurring_metadata` JSON or equivalent) so they are stored on the purchase order and can be used by notifications and smart-setting logic.

4. **Timezone**  
   Use the app timezone (`config('app.timezone')`) for `next_run_at` and scheduler. Ensure production cron runs in the same timezone (or set `APP_TIMEZONE` accordingly).

5. **Optional: Artisan command**  
   Add a command (e.g. `recurring-orders:process`) that dispatches `ProcessRecurringOrdersJob` once, for testing and manual runs.

## How to test

- **Unit**: RecurringOrderService::computeNextRunAt / computeNextRunAtFromModel (existing or add tests for timezone and next date).
- **Feature**: Dispatch the job (or run the command), create a recurring order with `next_run_at` in the past or near future, run the job, assert one purchase order is created and the recurring order’s status is `generated` and `next_run_at` is updated.
- **API**: Call in-progress and next-scheduling lists; after the job runs, the order should appear in in-progress (or equivalent) and not only in next-scheduling.

## Scheduler catch-up and timezone behaviour

- **Multiple orders due at once**: The job selects all due orders (up to 50 per run) and processes each; order of processing is not guaranteed.
- **Server or scheduler offline**: When the scheduler runs again, it selects orders with `next_run_at <= now()`, so a missed run is effectively caught on the next run (one occurrence per order per run; `next_run_at` is then advanced).
- **Past next_schedule_date**: Same as above: due orders are processed when the job runs, then `next_run_at` is set to the next occurrence (no batch catch-up for multiple missed dates).
- **Timezone**: All times use the application timezone (`config('app.timezone')`). Ensure production cron runs in that timezone (or set `APP_TIMEZONE` accordingly) so that “scheduled time” matches user expectations.

## Docs to read

- [spec.md](./spec.md) – requirements and success criteria  
- [plan.md](./plan.md) – technical summary and structure  
- [research.md](./research.md) – scheduler frequency, timezone, notification/smart_settings  
- [data-model.md](./data-model.md) – entities and optional schema  
- [contracts/process-recurring-orders-job.md](./contracts/process-recurring-orders-job.md) – job selection and write contract  

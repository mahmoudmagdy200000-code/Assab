# Research: Recurring Order Scheduled Execution

**Feature**: 003-recurring-order-scheduled-execution  
**Date**: 2026-03-09

## 1. Scheduler frequency for ProcessRecurringOrdersJob

**Decision**: Run ProcessRecurringOrdersJob every **5 minutes** via the Laravel scheduler in RecurringOrderServiceProvider.

**Rationale**: Spec SC-001 requires at least 95% of due recurring orders to have their order request created within an agreed time window (e.g. within 5 minutes of the scheduled time). Running the job every 5 minutes keeps the system within that window without overloading the queue. Laravel's `->everyFiveMinutes()` is the standard way to do this. Smaller intervals (e.g. every minute) are possible but add more queue jobs with little benefit for typical repeat frequencies (weekly/monthly).

**Alternatives considered**:
- Every minute: more precise but more jobs; 5 minutes is sufficient for the spec.
- Every 15 minutes: would exceed the 5-minute window and fail SC-001.
- Event-driven (e.g. trigger at exact time per order): more complex, requires a separate scheduler or many delayed jobs; current "poll due orders" design is simpler and already implemented.

---

## 2. Timezone for next_run_at and scheduled time

**Decision**: Use the application's default timezone (`config('app.timezone')`) for computing and comparing `next_run_at`. No change to how RecurringOrderService computes `next_run_at` (it already uses Carbon/now() which respect app timezone). Document in quickstart that production cron must run in the same timezone as the app (or set APP_TIMEZONE accordingly).

**Rationale**: Recurring orders store `scheduling_time_am` / `scheduling_time_pm` as time-of-day; the existing `computeNextRunAt` uses Carbon and the request/server timezone. Keeping a single source of truth (app timezone) avoids bugs where cron runs in UTC but next_run_at was computed in another zone. No schema or API change required.

**Alternatives considered**:
- Per-branch timezone: would require storing timezone on branch and using it in computeNextRunAt; adds scope and is not in the current spec.
- UTC everywhere: would require converting display and scheduling to branch local time elsewhere; out of scope.

---

## 3. Applying notification_options and smart_settings when creating the order

**Decision**:
- **notification_channels**: Pass the recurring order's `notification_channels` into `PurchaseOrderService::createOrder()` when creating the order from ProcessRecurringOrdersJob (replace the current hardcoded `['app']`). If the recurring order has no channels, keep a sensible default (e.g. `['app']`).
- **notification_options**: The recurring order stores options like `alert_24_hours_before`, `review_before_sending`, `send_automatically_without_review`. For "apply when order is triggered": (1) Pass them to the created purchase order if the purchase_orders table (or a JSON metadata column) supports them; or (2) Trigger any immediate notifications (e.g. "order generated from recurring") using the recurring order's notification_channels. If Purchase module does not have a metadata column for notification_options, add a JSON column (e.g. `recurring_metadata`) on purchase_orders to store `notification_options` and `smart_settings` from the recurring order for downstream use; otherwise store in a single JSON column and document for future notification/automation use.
- **smart_settings**: Similarly, pass recurring order's `smart_settings` into the created order (same JSON/metadata approach) so downstream logic (e.g. "freeze during holidays", "auto adjust quantities") can apply. Implementation of the actual smart behaviour (e.g. adjusting quantities) may be existing or future work; this feature ensures the settings are **passed** from recurring order to purchase order at creation time.

**Rationale**: Spec FR-004 and FR-005 require that notification options and Smart Settings are applied when the order is triggered. The minimal change is to propagate these from the recurring order to the purchase order at creation so that (a) notifications can use the right channels and options, and (b) any existing or future smart-setting logic can read them from the purchase order. If the Purchase module currently has no column for this, a small migration adding a JSON `recurring_metadata` (or reusing an existing metadata column) keeps the change bounded.

**Alternatives considered**:
- Only pass notification_channels and leave notification_options/smart_settings for a later feature: would partially satisfy FR-004/FR-005 by improving notifications; we choose to pass all so the created order is fully informed.
- Implement full smart behaviour (e.g. auto-adjust quantities) in this feature: out of scope; spec says "apply" (pass through), not implement all behaviours.

---

## 4. Where to register the schedule (RecurringOrderServiceProvider vs app bootstrap)

**Decision**: Register the recurring-orders job schedule in **RecurringOrderServiceProvider::registerCommandSchedules()**, following the same pattern as InventoryServiceProvider (e.g. `inventory:generate-daily-sessions`). Use `$schedule->job(ProcessRecurringOrdersJob::class)->everyFiveMinutes()` and ensure the Schedule class is imported (Illuminate\Console\Scheduling\Schedule).

**Rationale**: Keeps scheduling for recurring orders inside the RecurringOrder module; no change to app/Console or bootstrap. Matches existing project pattern (Inventory module registers its own schedule).

**Alternatives considered**:
- Central app schedule (e.g. routes/console.php or App\Console\Kernel): would work but project uses per-module registration.

---

## 5. Missed runs (server/scheduler offline)

**Decision**: Keep current job logic: select orders where `next_run_at <= now()`. No explicit "catch-up" for past dates: if the job runs at T+10 minutes, it will still pick orders whose next_run_at was at T. So a single missed run is effectively caught on the next run. For long outages (e.g. many days), orders with past next_run_at will all be picked in the next run; RecurringOrderService::computeNextRunAtFromModel uses the current `next_run_at` (or start_date) to compute the next occurrence, so after processing one occurrence the model is updated with the next_run_at for the following occurrence. No change to this behaviour; document in quickstart that "missed" runs are processed when the scheduler runs again (one occurrence per run per order, then next_run_at advances).

**Rationale**: Spec edge case says "either run missed on next run (catch-up) or skip and use next occurrence; behaviour must be consistent". Current design is "run all due orders (next_run_at <= now()) in one job run, then advance next_run_at". That effectively catches up one occurrence per order per run. Multiple missed occurrences would each be processed in subsequent runs (one run per occurrence per order). No code change; document.

**Alternatives considered**:
- Batch catch-up (create multiple orders for multiple missed dates): more complex and could create many orders at once; not required by spec.

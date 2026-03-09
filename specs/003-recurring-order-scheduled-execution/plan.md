# Implementation Plan: Recurring Order Scheduled Execution (3.1.2.6.1.1)

**Branch**: `003-recurring-order-scheduled-execution` | **Date**: 2026-03-09 | **Spec**: [spec.md](./spec.md)  
**Input**: Feature specification from `/specs/003-recurring-order-scheduled-execution/spec.md`

## Summary

Recurring orders are not executing at their scheduled time: they remain in "next scheduling" (Pending) with a `next_schedule_date` and never move to "in progress" or generate purchase orders. Root cause: **ProcessRecurringOrdersJob** exists and contains correct due-order selection and creation logic, but it is **never scheduled** (RecurringOrderServiceProvider schedule is commented out). Additionally, the job does not pass the recurring order’s **notification_options** or **smart_settings** (or its **notification_channels**) when creating the purchase order, so FR-004 and FR-005 are not met.

**Technical approach**: (1) Schedule ProcessRecurringOrdersJob at a fixed interval (e.g. every 5 minutes) from RecurringOrderServiceProvider so due orders are picked and executed. (2) Ensure timezone used for `next_run_at` is consistent (app timezone). (3) In the job, pass recurring order’s notification_channels, notification_options, and smart_settings into the purchase order creation (or into a dedicated notification step) so they are applied per spec. (4) Optionally add an Artisan command that dispatches the job for testing and manual runs. (5) Document scheduler setup (cron) for production.

## Technical Context

**Language/Version**: PHP 8.x  
**Primary Dependencies**: Laravel 11, RecurringOrder module, Purchase module (PurchaseOrderService), Nwidart Laravel Modules  
**Storage**: Existing DB; tables `recurring_orders`, `recurring_order_items`, `purchase_orders`. Core fix requires no schema change; an optional `recurring_metadata` column on `purchase_orders` is allowed per data-model.md for FR-004/FR-005.  
**Testing**: PHPUnit; feature tests for API; unit tests for RecurringOrderService (computeNextRunAt); job/feature test for ProcessRecurringOrdersJob  
**Target Platform**: Linux server (Laravel app + cron for scheduler)  
**Project Type**: Web service (Laravel API); modular structure under `app/Modules/`  
**Performance Goals**: Recurring orders executed within agreed window (e.g. 5 minutes of scheduled time); API ≤500ms p95  
**Constraints**: Scheduler must run at least every 5 minutes to meet SC-001; no new tables (optional new column on existing table per data-model)  
**Scale/Scope**: Existing recurring order volume; job processes up to 50 due orders per run (existing limit)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|-----------|--------|--------|
| **I. Clean Architecture** | Pass | Controller → Service → Repository → Model already used; job uses RecurringOrderService and PurchaseOrderService; no business logic in controller for this fix. |
| **II. Testing** | Pass | Unit tests for service (next run computation); feature test for job execution and list visibility; API tests already cover lists. |
| **III. UX Consistency** | Pass | No new endpoints; existing response format and HTTP codes unchanged. |
| **IV. Performance** | Pass | Job is queued (ProcessRecurringOrdersJob implements ShouldQueue); scheduling is async; API unchanged. |
| **V. Security** | Pass | No new endpoints; auth/authorization unchanged; job runs in backend context. |

## Project Structure

### Documentation (this feature)

```text
specs/003-recurring-order-scheduled-execution/
├── plan.md              # This file
├── research.md          # Phase 0
├── data-model.md        # Phase 1
├── quickstart.md        # Phase 1
├── contracts/           # Phase 1 (optional: job input/output)
└── tasks.md             # Phase 2 (/speckit.tasks – not created by /speckit.plan)
```

### Source Code (repository root)

```text
app/
├── Modules/
│   ├── RecurringOrder/
│   │   ├── app/
│   │   │   ├── Jobs/
│   │   │   │   └── ProcessRecurringOrdersJob.php   # Fix: apply notification/smart_settings; keep logic
│   │   │   └── Services/
│   │   │       └── RecurringOrderService.php      # No change or minor (next_run_at timezone)
│   │   └── Providers/
│   │       └── RecurringOrderServiceProvider.php  # Add: schedule ProcessRecurringOrdersJob every 5 min
│   └── Purchase/
│       └── app/Services/
│           └── PurchaseOrderService.php          # Optional: accept notification_options/smart_settings if needed
tests/
└── (existing structure; add unit/feature tests for RecurringOrder + job)
```

**Structure Decision**: Changes are confined to the RecurringOrder module (scheduler registration, job payload) and optionally Purchase module if createOrder is extended to accept recurring-order metadata. No new modules or top-level apps.

## Complexity Tracking

> No constitution violations. This section is empty.

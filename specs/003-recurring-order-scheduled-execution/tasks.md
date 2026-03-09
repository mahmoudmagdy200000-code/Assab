# Tasks: Recurring Order Scheduled Execution (3.1.2.6.1.1)

**Input**: Design documents from `specs/003-recurring-order-scheduled-execution/`  
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/

**Organization**: Tasks are grouped by user story so each story can be implemented and tested independently.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: User story (US1, US2, US3)
- Include exact file paths in descriptions

## Path Conventions

- RecurringOrder module: `Modules/RecurringOrder/`
- Purchase module: `Modules/Purchase/`
- Repository root: project root

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Verify environment and module structure before implementation

- [x] T001 Verify RecurringOrder and Purchase module structure and Laravel scheduler usage per plan in `Modules/RecurringOrder/app/Providers/RecurringOrderServiceProvider.php` and `Modules/RecurringOrder/app/Jobs/ProcessRecurringOrdersJob.php`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Ensure job selection logic matches contract before scheduling

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [x] T002 Verify ProcessRecurringOrdersJob selection query matches contract (status=pending, next_run_at<=now(), paused_at null, end_date, limit 50) in `Modules/RecurringOrder/app/Jobs/ProcessRecurringOrdersJob.php`

**Checkpoint**: Foundation ready – user story implementation can begin

---

## Phase 3: User Story 1 – Recurring Order Executes at Scheduled Time (Priority: P1) – MVP

**Goal**: Schedule ProcessRecurringOrdersJob every 5 minutes so due recurring orders are picked and executed; order appears in in-progress list and a purchase order is created.

**Independent Test**: Create a recurring order with next_run_at in the past or near future, run the job (or wait for scheduler), then verify the order appears in the in-progress list and a purchase order exists.

### Tests for User Story 1 (Constitution II: feature test for job)

- [x] T012 [P] [US1] Add feature test for ProcessRecurringOrdersJob: dispatch job, assert due recurring order gets one purchase order created and recurring order status/next_run_at updated, in `tests/Feature/ProcessRecurringOrdersJobTest.php`

### Implementation for User Story 1

- [x] T003 [P] [US1] Register ProcessRecurringOrdersJob in Laravel scheduler (everyFiveMinutes) with Schedule import in `Modules/RecurringOrder/app/Providers/RecurringOrderServiceProvider.php` method registerCommandSchedules()
- [x] T004 [US1] Add Artisan command that dispatches ProcessRecurringOrdersJob once (e.g. recurring-orders:process) in `Modules/RecurringOrder/app/Console/` and register it in RecurringOrderServiceProvider

**Checkpoint**: User Story 1 is complete; recurring orders execute when the scheduler runs

---

## Phase 4: User Story 2 – Notifications and Smart Settings Apply on Execution (Priority: P2)

**Goal**: When a recurring order is triggered, pass its notification_channels, notification_options, and smart_settings to the created purchase order so they are applied per FR-004 and FR-005.

**Independent Test**: Create a recurring order with notification_options and smart_settings, set a near-future schedule, run the job, then verify the created purchase order has the recurring order’s notification_channels and (if supported) notification_options/smart_settings stored.

### Implementation for User Story 2

- [x] T005 [US2] In ProcessRecurringOrdersJob::processOne build createOrder payload with notification_channels from recurring order (fallback to ['app']) in `Modules/RecurringOrder/app/Jobs/ProcessRecurringOrdersJob.php`
- [x] T006 [P] [US2] If purchase_orders has no column for recurring metadata (check `Modules/Purchase/app/Models/PurchaseOrder.php` and migrations), add migration for recurring_metadata (JSON nullable) in `Modules/Purchase/database/migrations/` and add fillable/casts in `Modules/Purchase/app/Models/PurchaseOrder.php`
- [x] T007 [US2] Update PurchaseOrderService::createOrder to accept and persist recurring_metadata (notification_options, smart_settings) in `Modules/Purchase/app/Services/PurchaseOrderService.php`
- [x] T008 [US2] In ProcessRecurringOrdersJob::processOne pass notification_options and smart_settings into createOrder payload (as recurring_metadata or equivalent) in `Modules/RecurringOrder/app/Jobs/ProcessRecurringOrdersJob.php`

**Checkpoint**: User Story 2 is complete; created orders carry notification and smart settings from the recurring order

---

## Phase 5: User Story 3 – Paused and Inactive Orders Do Not Run (Priority: P3)

**Goal**: Confirm that paused and non-pending recurring orders are never selected by the job (FR-006, SC-004).

**Independent Test**: Pause a recurring order that would be due soon; run the job; verify no purchase order was created for that recurring order.

### Implementation for User Story 3

- [x] T009 [US3] Verify ProcessRecurringOrdersJob query excludes paused_at IS NOT NULL and status != pending; add a short comment in `Modules/RecurringOrder/app/Jobs/ProcessRecurringOrdersJob.php` documenting exclusion for FR-006/SC-004

**Checkpoint**: User Story 3 is verified; paused/inactive orders do not run

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Documentation and validation

- [x] T010 [P] Document production cron setup for Laravel scheduler (e.g. in `specs/003-recurring-order-scheduled-execution/quickstart.md` or project README)
- [ ] T011 Run quickstart validation: create a recurring order with next_run_at in the past, run the job (or command), confirm order in in-progress list and one purchase order created (manual)
- [x] T013 [P] Add unit test for RecurringOrderService::computeNextRunAt or computeNextRunAtFromModel in `tests/Unit/Modules/RecurringOrder/RecurringOrderServiceTest.php` (Constitution II: unit tests for services)
- [x] T014 [P] Document scheduler catch-up and timezone behaviour (multiple orders due at once, server offline, past next_schedule_date, app timezone) in `specs/003-recurring-order-scheduled-execution/quickstart.md` per research.md

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies – start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 – blocks all user stories
- **Phase 3 (US1)**: Depends on Phase 2 – MVP (schedule the job)
- **Phase 4 (US2)**: Depends on Phase 2; can start after or in parallel with Phase 3 (different files: Job + Purchase module)
- **Phase 5 (US3)**: Depends on Phase 2; verification only, can run after Phase 3
- **Phase 6 (Polish)**: Depends on Phase 3 (and optionally 4, 5) being done; includes T013 (unit test), T014 (edge-case documentation)

### User Story Dependencies

- **US1 (P1)**: No dependency on US2/US3 – schedule job only
- **US2 (P2)**: No dependency on US1 for code changes; job payload changes in same file as US1, so implement US1 first then US2 in same job file
- **US3 (P3)**: Verification of existing query; no dependency on US1/US2

### Within Each User Story

- US1: T003 (schedule) then T004 (command) then T012 (feature test for job)
- US2: T005 (channels) then T006 (migration if needed), T007 (createOrder), T008 (pass options/settings)
- US3: T009 (verify + comment)
- Polish: T010, T011, T013 (unit test for service), T014 (edge-case docs)

### Parallel Opportunities

- T003 and T006 can run in parallel (different modules)
- T010 (docs) can run in parallel with any implementation
- After Phase 2, T003 (US1) and T006 (US2 migration) can be done in parallel; T007–T008 depend on T006

---

## Parallel Example: After Phase 2

```text
# US1 and US2 (migration part) in parallel:
T003: Register schedule in Modules/RecurringOrder/app/Providers/RecurringOrderServiceProvider.php
T006: Add recurring_metadata migration in Modules/Purchase/database/migrations/ and update PurchaseOrder model
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1 and Phase 2
2. Complete Phase 3 (T003, T004)
3. **STOP and VALIDATE**: Run job manually or wait for scheduler; check in-progress list and purchase_orders table
4. Deploy/demo

### Incremental Delivery

1. Phase 1 + 2 → foundation ready
2. Phase 3 (US1) → test independently → MVP
3. Phase 4 (US2) → test notification/smart_settings on created order
4. Phase 5 (US3) → verify paused/inactive exclusion
5. Phase 6 → docs and quickstart validation

### Parallel Team Strategy

- Developer A: Phase 3 (US1) – scheduler + command
- Developer B: Phase 4 (US2) – migration (T006), then PurchaseOrderService (T007), then job payload (T005, T008)
- Phase 5 and 6 can follow after US1/US2

---

## Notes

- [P] = different files, no dependencies
- [USn] = task belongs to that user story for traceability
- Constitution II requires unit tests for services and feature tests for API/job behaviour; T012 (feature test) and T013 (unit test) satisfy this for the recurring-order execution feature
- Commit after each task or logical group
- If Purchase module already has a metadata column for recurring data, skip T006 and use it in T007/T008

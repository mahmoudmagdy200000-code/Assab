# Tasks: Timeline Sort and Inventory Details Timeline

**Input**: Design documents from `specs/002-timeline-sort-inventory-details/`  
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: Not explicitly requested in the feature specification; optional test task included in Polish phase.

**Organization**: Tasks grouped by user story for independent implementation and testing.

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: User story (US1, US2)
- File paths are in task descriptions

---

## Phase 1: Setup

**Purpose**: Confirm feature context and docs

- [x] T001 [P] Verify feature branch `002-timeline-sort-inventory-details` and presence of spec.md, plan.md, research.md, data-model.md, quickstart.md in specs/002-timeline-sort-inventory-details/

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared checklist so all timeline-emitting endpoints are covered

- [x] T002 Confirm timeline contract in specs/002-timeline-sort-inventory-details/contracts/timeline-detail-response.md and list of affected files from specs/002-timeline-sort-inventory-details/plan.md (Expense, Purchase, Custody, Inventory)

**Checkpoint**: Foundation ready — User Story 1 and 2 implementation can proceed

---

## Phase 3: User Story 1 – Chronological Timeline in All Detail Views (Priority: P1) — MVP

**Goal**: Every detail and dedicated timeline endpoint returns timeline events ordered by event date/time ascending (oldest first).

**Independent Test**: Call any detail or timeline endpoint (e.g. expense details, order details, GET …/timeline). Assert the `timelines` (or `timeline`) array is ordered by event time ascending (oldest first).

### Implementation for User Story 1

- [x] T003 [P] [US1] Change findForShow timelines eager-load to orderBy('created_at', 'asc') in Modules/Expense/app/Repositories/ExpenseRepository.php
- [x] T004 [P] [US1] Change timeline() to orderBy('created_at', 'asc') in Modules/Expense/app/Http/Controllers/ExpenseController.php
- [x] T005 [P] [US1] Change timeline() to orderBy('created_at', 'asc') in Modules/Expense/app/Http/Controllers/ExpenseApprovalController.php
- [x] T006 [P] [US1] Change timelines() relation to orderBy('occurred_at', 'asc') in Modules/Purchase/app/Models/PurchaseOrder.php
- [x] T007 [P] [US1] Change timelines() relation to orderBy('occurred_at', 'asc') in Modules/Purchase/app/Models/ReturnOrder.php
- [x] T008 [P] [US1] Change timelines() relation to orderBy('occurred_at', 'asc') in Modules/Purchase/app/Models/GoodsReceipt.php
- [x] T009 [P] [US1] Change timelines() relation to orderBy('occurred_at', 'asc') in Modules/Purchase/app/Models/PurchaseVariance.php
- [x] T010 [P] [US1] Change timelines() relation to orderBy('occurred_at', 'asc') in Modules/Purchase/app/Models/CompensatoryOrder.php
- [x] T011 [US1] Change getOrderTimeline() to orderBy('occurred_at', 'asc') in Modules/Purchase/app/Services/PurchaseOrderService.php
- [x] T012 [P] [US1] Ensure CustodyRequest::timeline() uses orderBy('action_date', 'asc') in Modules/Custody/app/Models/CustodyRequest.php
- [x] T013 [US1] Change getTimelines() to orderBy('occurred_at', 'asc') in Modules/Inventory/app/Services/MonthlyInventoryService.php

**Checkpoint**: User Story 1 complete — all timeline data sources return ascending order; verify with expense/purchase/custody/inventory detail or timeline endpoints.

---

## Phase 4: User Story 2 – Inventory Details Include Timeline in Same Shape as Expense (Priority: P2)

**Goal**: Daily quick session details and monthly inventory details responses include a `timelines` array in the same shape as Expense/Purchase (id, event_type, name, image, occurred_at), ordered oldest first.

**Independent Test**: Call inventory session details (getSession) and monthly inventory details (show). Assert response contains `timelines` array; each entry has id, event_type, name, image (or null), occurred_at; order is ascending by occurred_at.

### Implementation for User Story 2

- [x] T014 [US2] Extend UnifiedTimelineResource for InventorySessionTimeline and MonthlyInventoryTimeline (id, event_type, name, image, occurred_at) in app/Http/Resources/UnifiedTimelineResource.php
- [x] T015 [P] [US2] Eager-load timelines with orderBy('occurred_at', 'asc') in getSession() in Modules/Inventory/app/Http/Controllers/DailyQuickInventoryController.php
- [x] T016 [P] [US2] Add timelines key using UnifiedTimelineResource::collection when timelines loaded in Modules/Inventory/app/Transformers/InventorySessionResource.php
- [x] T017 [US2] Eager-load timelines with orderBy('occurred_at', 'asc') in show() in Modules/Inventory/app/Http/Controllers/MonthlyInventoryController.php
- [x] T018 [US2] Add timelines key using UnifiedTimelineResource::collection when timelines loaded in Modules/Inventory/app/Transformers/MonthlyInventoryResource.php

**Checkpoint**: User Story 2 complete — inventory session and monthly inventory detail responses include unified `timelines` array; verify with getSession and monthly show.

---

## Phase 5: Polish & Cross-Cutting Concerns

**Purpose**: Verification and optional tests

- [x] T019 [P] Run quickstart.md verification steps in specs/002-timeline-sort-inventory-details/quickstart.md (detail/timeline endpoints, order asc, empty timelines as [])
- [ ] T020 [P] Optional: Add feature tests for timeline ascending order and inventory details timelines (e.g. in Modules/Expense, Modules/Inventory) per constitution testing standards

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 — blocks user stories
- **Phase 3 (US1)**: Depends on Phase 2 — can start after T002
- **Phase 4 (US2)**: Depends on Phase 2 — can start after T002 (independent of US1)
- **Phase 5 (Polish)**: Depends on Phase 3 and 4 completion

### User Story Dependencies

- **User Story 1 (P1)**: No dependency on US2. All T003–T013 can run after T002.
- **User Story 2 (P2)**: No dependency on US1. T014 must complete before T015–T018 (resource extended first); T015/T016 can run in parallel with T017/T018.

### Within Each User Story

- **US1**: All sort-order changes (T003–T013) are independent per file; T003–T012 can run in parallel; T013 is single file.
- **US2**: T014 first (UnifiedTimelineResource); then T015+T016 (daily) and T017+T018 (monthly) can run in parallel.

### Parallel Opportunities

- **Phase 1**: T001 [P]
- **Phase 3**: T003–T010, T012 [P]; T011 and T013 are single-file, no conflict with others
- **Phase 4**: T015+T016 [P], T017+T018 [P] (after T014)
- **Phase 5**: T019, T020 [P]

---

## Parallel Example: User Story 1

```bash
# Run all Expense + Purchase model/controller changes in parallel:
T003: ExpenseRepository (findForShow timelines asc)
T004: ExpenseController (timeline asc)
T005: ExpenseApprovalController (timeline asc)
T006: PurchaseOrder (timelines asc)
T007: ReturnOrder (timelines asc)
T008: GoodsReceipt (timelines asc)
T009: PurchaseVariance (timelines asc)
T010: CompensatoryOrder (timelines asc)
T012: CustodyRequest (timeline action_date asc)
# Then:
T011: PurchaseOrderService (getOrderTimeline asc)
T013: MonthlyInventoryService (getTimelines asc)
```

---

## Parallel Example: User Story 2

```bash
# After T014 (UnifiedTimelineResource):
T015 + T016: Daily quick (controller getSession + InventorySessionResource)
T017 + T018: Monthly (controller show + MonthlyInventoryResource)
# T015/T016 and T017/T018 are independent
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1 (T001) and Phase 2 (T002)
2. Complete Phase 3 (T003–T013)
3. **Stop and validate**: Call expense/purchase/custody/inventory detail or timeline endpoints; assert timelines ordered oldest first
4. Deploy/demo if ready

### Incremental Delivery

1. Phase 1 + 2 → foundation ready
2. Add User Story 1 (T003–T013) → validate order asc → deploy (MVP)
3. Add User Story 2 (T014–T018) → validate inventory details have `timelines` in unified shape → deploy
4. Phase 5 (T019–T020) → verification and optional tests

### Parallel Team Strategy

- After T002: Developer A does US1 (all sort-order changes); Developer B does US2 (UnifiedTimelineResource then inventory detail + resources). Stories are independent.

---

## Notes

- [P] = different files, safe to run in parallel
- [US1]/[US2] = task belongs to that user story for traceability
- Each user story is independently testable; empty timelines must be `[]`
- Commit after each task or logical group; stop at any checkpoint to validate

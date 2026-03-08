# Tasks: Refactor Modules for Cleaner and Optimized Code

**Input**: Design documents from `/specs/001-refactor-modules-optimization/`  
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/

**Organization**: Tasks are grouped by user story to enable independent implementation and verification. This is a refactor feature: no new features or schema changes; all work preserves identical API responses.

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Can run in parallel (different files/modules, no dependencies)
- **[Story]**: User story (US1, US2, US3)
- Include exact file paths in descriptions

---

## Phase 1: Setup (Verification Baseline)

**Purpose**: Establish baseline and ensure verification mechanism is in place before refactoring.

- [x] T001 Run full test suite from repository root (`php artisan test`) and record baseline result; document any failing tests that are in-scope for Expense, Shift, or Purchase in `specs/001-refactor-modules-optimization/checklists/` or project docs
- [x] T002 Document in-scope API endpoints from `Modules/Expense/routes/api.php`, `Modules/Shift/routes/api.php`, and `Modules/Purchase/routes/api.php` per contracts/README.md so verification can target all affected routes
- [x] T003 [P] Confirm environment and dependencies (PHP 8.x, Composer, Laravel) are ready per `specs/001-refactor-modules-optimization/quickstart.md`

---

## Phase 2: Foundational (Refactor Pattern Template)

**Purpose**: Establish Controller → Service → Repository pattern in one module so subsequent work is consistent. No response change.

**⚠️ CRITICAL**: Complete this phase before bulk refactor in Phase 4.

- [x] T004 Pick one controller in `Modules/Expense/app/Http/Controllers/` that still contains business logic (e.g. ExpenseController, QuickCashExpenseController, or SingleInvoiceExpenseController) and extract remaining logic into existing or new service in `Modules/Expense/app/Services/`; thin controller to HTTP only; run `php artisan test` and fix any regression
- [x] T005 If data access in that controller is not behind a repository, introduce or use a repository in `Modules/Expense/app/Repositories/` (create directory if missing) and move queries there; keep response output identical; run tests again
- [x] T006 Document the refactor pattern (controller method → service call → repository where applicable) in `specs/001-refactor-modules-optimization/quickstart.md` or a short REFACTOR-PATTERN.md in the spec folder so Shift and Purchase follow the same approach

**Checkpoint**: One Expense flow refactored; tests pass; pattern documented. Proceed to US1 verification then US2/US3.

---

## Phase 3: User Story 1 – Maintain Identical API Behavior (Priority: P1) 🎯 MVP

**Goal**: Ensure every endpoint in Expense, Shift, and Purchase returns exactly the same response (body, status, error format) after any refactor. Verification is the deliverable.

**Independent Test**: Call affected endpoints with fixed inputs before and after refactor; compare responses (byte/schema or existing test assertions). All existing tests pass with no change to expected results.

### Implementation for User Story 1

- [x] T007 [US1] After any refactor in Phase 2, run `php artisan test` (or `php artisan test --filter='Expense|Shift|Purchase'` if scoped); fix any failure so expectations remain unchanged per spec FR-005
- [x] T008 [US1] Add or run verification for error responses: trigger validation failure, not-found, and unauthorized for at least one endpoint per module; confirm error body and status match pre-refactor (manual or automated) per spec FR-002
- [x] T009 [US1] For paginated/list endpoints in `Modules/Expense`, `Modules/Shift`, and `Modules/Purchase`, confirm same items, order, and metadata (e.g. total count) for same query parameters after refactor per spec acceptance scenario 2

**Checkpoint**: Verification steps documented and passing; no response change detected.

---

## Phase 4: User Story 2 – Improved Code Quality and Maintainability (Priority: P2)

**Goal**: Refactor Expense, Shift, and Purchase so business logic lives in services, data access in repositories where appropriate, and controllers are thin. Preserve all API responses.

**Independent Test**: Code review and static checks; existing tests pass; no change to response structure or status.

### Implementation for User Story 2

- [x] T010 [P] [US2] Audit controllers in `Modules/Expense/app/Http/Controllers/` (ExpenseController, QuickCashExpenseController, SingleInvoiceExpenseController, GroupedInvoiceExpenseController, PreApprovalRequestController, ExpenseApprovalController, CategoryController, SupplierController, ExpenseAttachmentController); move any remaining business logic to `Modules/Expense/app/Services/` and data access to `Modules/Expense/app/Repositories/`; keep controllers thin; run tests after each controller or batch
- [x] T011 [P] [US2] Audit controllers in `Modules/Shift/app/Http/Controllers/` (ShiftEndController, PendingShiftController, InProgressShiftController, CompletedShiftController, ReassignmentShiftController, ShiftController, ShiftHandoverController, ShiftVarianceController, ShiftRequestsController, BranchManagerShiftController, CashierShiftController, CashierManagementController); move any remaining business logic to `Modules/Shift/app/Services/` and data access to `Modules/Shift/app/Repositories/`; keep controllers thin; run tests after each controller or batch
- [x] T012 [P] [US2] Audit controllers in `Modules/Purchase/app/Http/Controllers/` (GoodsReceivingController, NewOrderController, PendingOrderController, PurchaseHistoryController, ReturnManagementController, SupplierController, SupplierInfoController, and any others in that directory); move any remaining business logic to `Modules/Purchase/app/Services/` and data access to `Modules/Purchase/app/Repositories/`; keep controllers thin; run tests after each controller or batch
- [x] T013 [US2] Where validation is moved to Form Requests in the three modules, preserve exact validation rules and message keys/values so error responses stay identical per research.md and spec FR-002
- [x] T014 [US2] Centralize or reuse common behavior across Expense, Shift, and Purchase only where it does not alter responses; prefer module-local services/repositories per research.md and spec FR-007
- [x] T015 [US2] Ensure methods in new or updated services/repositories in `Modules/Expense`, `Modules/Shift`, and `Modules/Purchase` stay under 20 lines with early returns and guard clauses; add PHPDoc for public methods per constitution

**Checkpoint**: All three modules follow Controller → Service → Repository; existing tests pass; responses unchanged.

---

## Phase 5: User Story 3 – Performance and Resource Optimization (Priority: P3)

**Goal**: Same or better response time and same or fewer data access operations; no change to response body or status.

**Independent Test**: Compare response time and query count for representative requests before and after; responses must remain identical.

### Implementation for User Story 3

- [x] T016 [P] [US3] In `Modules/Expense`, add eager loading (`with()`) for relationships used in API responses; add selective `select()` only where response shape is unchanged; fix N+1 in list/detail endpoints; use `chunk()` for any large iterations; run tests to confirm no response change
- [x] T017 [P] [US3] In `Modules/Shift`, add eager loading (`with()`) for relationships used in API responses; add selective `select()` only where response shape is unchanged; fix N+1 in list/detail endpoints; use `chunk()` for any large iterations; run tests to confirm no response change
- [x] T018 [P] [US3] In `Modules/Purchase`, add eager loading (`with()`) for relationships used in API responses; add selective `select()` only where response shape is unchanged; fix N+1 in list/detail endpoints; use `chunk()` for any large iterations; run tests to confirm no response change
- [x] T019 [US3] Verify transaction boundaries and side-effect ordering in refactored code match pre-refactor behavior so database state and notifications remain identical per spec FR-004
- [x] T020 [US3] Optionally profile or log query count for a representative set of requests (per quickstart.md); document that response time and resource usage are unchanged or improved per spec SC-004

**Checkpoint**: No N+1; same or fewer queries per request; responses unchanged; performance same or better.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Consistency, documentation, and final validation.

- [x] T021 [P] Add or update PHPDoc for all public methods in new or modified services and repositories in `Modules/Expense`, `Modules/Shift`, and `Modules/Purchase`
- [x] T022 Run full test suite and in-scope feature tests; confirm all pass with no change to expected results per spec SC-002
- [x] T023 Run quickstart.md validation (run app, run tests, verify refactor workflow) and update quickstart if paths or commands changed
- [x] T024 Final code review: confirm no new public API surface, no response shape or status change, and constitution (Controller → Service → Repository, methods ≤20 lines, eager loading, no N+1) is satisfied across the three modules

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies – start immediately.
- **Phase 2 (Foundational)**: Depends on Phase 1 – establishes pattern; blocks confident bulk refactor.
- **Phase 3 (US1)**: Depends on Phase 2 – verification runs after first refactor; can be repeated after Phase 4/5.
- **Phase 4 (US2)**: Depends on Phase 2 – code quality refactor; T010–T012 can run in parallel by module.
- **Phase 5 (US3)**: Depends on Phase 4 – performance optimization assumes logic is already in services/repositories.
- **Phase 6 (Polish)**: Depends on Phase 3, 4, 5 – final validation and docs.

### User Story Dependencies

- **US1 (P1)**: Verification; runs after Phase 2 and after any refactor step to confirm no response change.
- **US2 (P2)**: Code quality; can proceed module-by-module in parallel (Expense, Shift, Purchase) after Phase 2.
- **US3 (P3)**: Performance; runs after US2 refactor so query optimization is applied to service/repository layer.

### Parallel Opportunities

- T003 can run in parallel with T001/T002.
- T010 (Expense), T011 (Shift), T012 (Purchase) can run in parallel once Phase 2 is done.
- T016 (Expense), T017 (Shift), T018 (Purchase) can run in parallel once Phase 4 is done.
- T021 can be done in parallel with T022/T023.

---

## Parallel Example: User Story 2 (by module)

```text
# After Phase 2, refactor each module in parallel (different developers or batches):
T010: Audit and refactor Expense controllers → Services/Repositories in Modules/Expense/
T011: Audit and refactor Shift controllers → Services/Repositories in Modules/Shift/
T012: Audit and refactor Purchase controllers → Services/Repositories in Modules/Purchase/
# Run tests after each module (or after each controller) to catch regressions early.
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup (baseline, in-scope endpoints).
2. Complete Phase 2: Foundational (one controller refactored, pattern documented).
3. Complete Phase 3: US1 verification (tests pass, error/pagination checks).
4. **STOP and VALIDATE**: Deploy or demo only if no response change is confirmed.

### Incremental Delivery

1. Phase 1 + 2 → Pattern established.
2. Add US2 per module (Expense → Shift → Purchase); run US1 verification after each module.
3. Add US3 (performance) per module; run tests and optional profiling.
4. Phase 6 polish and final review.

### Parallel Team Strategy

- After Phase 2: Developer A – Expense (US2 then US3); Developer B – Shift (US2 then US3); Developer C – Purchase (US2 then US3). Each runs tests after their changes; integrate when all modules pass.

---

## Notes

- Every task that changes code must be followed by running the test suite (or in-scope tests) to ensure no response or behavior change.
- [P] tasks = different modules or files with no dependency on each other’s completion.
- [USn] label maps task to user story for traceability.
- No new endpoints, no schema changes, no change to API response body, status, or error format (production constraint).

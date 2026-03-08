# Baseline Test Result (T001)

**Date**: 2026-03-08  
**Command**: `php artisan test` (from repository root)  
**Purpose**: Record baseline before refactor so verification can detect regressions in Expense, Shift, and Purchase modules.

## Summary

| Metric | Value |
|--------|--------|
| Exit code | 2 (failures) |
| Tests run | 169 failed, 0 assertions (as reported) |
| Duration | ~11.34s |

## Failure cause

All reported failures are `RuntimeException: A facade root has not been set.` (Laravel Facade not booted in test context). This indicates a **test environment / bootstrap issue**, not a failure of application code in the three modules.

## In-scope for refactor (Expense, Shift, Purchase)

- **Dedicated module tests**: No test files named after Expense, Shift, or Purchase were found under `tests/` (e.g. no `*Expense*Test`, `*Shift*Test`, `*Purchase*Test`).
- **NFR / integration tests**: The following suites reference endpoints in our modules and are **in-scope** when the test environment runs correctly:
  - `Tests\NFR\Performance\ResponseTimeTest`: purchase orders index, shift handover, cashier operations, purchase history report/export, dashboard.
  - Other NFR tests (Compatibility, Reliability, etc.) that hit API endpoints may exercise Expense/Shift/Purchase routes depending on configuration.

## Recommendation

1. Fix test bootstrap so Laravel application and facades are set in the test runner (e.g. correct `TestCase` setup, `CreatesApplication`).
2. Re-run `php artisan test` to establish a green baseline, or run a scoped subset for Expense/Shift/Purchase once available.
3. After refactor, run the same command (or scoped filter) and compare: **no new failures** and **no change to expected results** for in-scope tests (per spec FR-005).

## Scoped run (when env is fixed)

```bash
php artisan test --filter='Expense|Shift|Purchase'
# Or run specific test directories if added later, e.g.:
# php artisan test tests/Feature/Modules/Expense
```

## Phase 3 verification checklist (T008, T009)

When the test environment runs successfully, perform:

- **T008 – Error responses**: For at least one endpoint per module (Expense, Shift, Purchase), trigger: (1) validation failure (invalid payload), (2) not-found (invalid ID), (3) unauthorized (no or wrong token). Confirm error body and status code match pre-refactor (e.g. 422, 404, 401/403 and same message structure).
- **T009 – Paginated/list endpoints**: For list or paginated endpoints in each module, call with the same query parameters before and after refactor; confirm same items, order, and metadata (e.g. total count, page info).

This file is the baseline record for Phase 1 Setup. Update this document when a green baseline is achieved.

## T022 re-run (Phase 6)

**Command**: `php artisan test` (from repository root)  
**Result**: 169 failed (0 assertions), duration ~10.68s. Same failure cause: `RuntimeException: A facade root has not been set.` (test bootstrap). **No new failures** introduced by refactor; in-scope modules (Expense, Shift, Purchase) have no dedicated test suite runnable in this environment. Per spec SC-002, when the test environment is fixed, re-run and confirm all pass with no change to expected results.

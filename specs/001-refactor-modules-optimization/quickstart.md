# Quickstart: Refactor Modules (001-refactor-modules-optimization)

**Branch**: `001-refactor-modules-optimization`

## Objective

Refactor Expense, Shift, and Purchase modules for cleaner, optimized code **without changing any API response**. Use this guide to run the app, run tests, and verify behavior during refactor.

## Prerequisites

- PHP 8.x, Composer, Laravel project dependencies installed.
- Database and environment configured (e.g. `.env`).
- Optional: Laravel Telescope or query logging to verify N+1 and query count.

## Run the application

From repository root:

```bash
php artisan serve
# Or use existing dev setup (e.g. Valet, Sail, or reverse proxy).
```

API base is typically `http://localhost:8000/api` (or your configured URL). Expense routes live under the branch-manager expense prefix; Shift under branch-manager; Purchase under `api/v1/purchase` (see `Modules/*/routes/api.php` and main `routes/api.php` for exact prefix).

## Run tests

```bash
# All tests
php artisan test

# Only modules in scope (if namespaced)
php artisan test --testsuite=Feature --filter='Expense|Shift|Purchase'
# Or run specific module test directories if present, e.g.:
# php artisan test modules/Expense modules/Shift modules/Purchase
```

After every refactor step, run the full test suite (or at least the in-scope tests) and ensure no expectations are changed; failures indicate behavior or response change and must be fixed before proceeding.

## Verify API responses (no change)

1. **Automated**: Rely on existing feature tests that hit these endpoints; they must pass without modification of expected JSON/status.
2. **Optional**: For critical endpoints, capture a “before” response (e.g. with `curl` or Postman), refactor, then compare “after” response (body + status) to ensure byte- or schema-equivalence.
3. **Errors**: Trigger validation, not-found, and unauthorized cases before and after; confirm error body and status code are unchanged.

## Refactor workflow (recommended)

1. Pick one module (e.g. Expense) or one controller at a time.
2. **Controller → Service → Repository**: Extract business logic to a Service; move data access to a Repository (e.g. `Modules/Expense/app/Repositories/SupplierRepository.php`). Controller: validate input → call service/repository → return same response (same Resource/array structure, same status code). See `specs/001-refactor-modules-optimization/REFACTOR-PATTERN.md` for the template.
3. Keep controllers thin: no `Model::` or `DB::` in controller; same response contract.
4. Fix N+1 with `with()` and add selective `select()` only where response shape stays the same.
5. Run tests after each step; fix any failure before continuing.
6. Repeat for Shift and Purchase.

## Key paths

- **Expense**: `Modules/Expense/` (Controllers in `app/Http/Controllers/`, Repositories in `app/Repositories/`, routes in `routes/api.php`).
- **Shift**: `Modules/Shift/` (Controllers in `app/Http/Controllers/`, Repositories in `app/Repositories/`, routes in `routes/api.php`).
- **Purchase**: `Modules/Purchase/` (Controllers in `app/Http/Controllers/`, Repositories in `app/Repositories/`, routes in `routes/api.php`).
- **Spec / plan**: `specs/001-refactor-modules-optimization/` (spec.md, plan.md, research.md, data-model.md, contracts/, checklists/, performance-notes.md).

## Performance verification (optional, T020)

- Use Laravel Telescope or query logging to compare query count for representative requests (e.g. expense list, shift show, purchase order by branch) before and after refactor.
- Confirm response time and resource usage are unchanged or improved; responses must remain identical. See `specs/001-refactor-modules-optimization/performance-notes.md` for eager loading and transaction verification.

## Success criteria (from spec)

- All existing tests pass with no change to expected results.
- Every affected endpoint returns the same response for the same request.
- Code shows improved structure (e.g. Controller → Service → Repository) and same or better performance (no N+1, same or lower query count/response time).

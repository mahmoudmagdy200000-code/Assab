# API Contract: Expense, Shift, Purchase Modules

**Feature**: 001-refactor-modules-optimization  
**Rule**: Refactor MUST NOT change any API response (body, status code, or error format).

## Contract Type

- **Exposed interface**: REST API endpoints under the Expense, Shift, and Purchase modules.
- **Contract**: **Existing response behavior is the contract.** No field may be added, removed, renamed, or reordered. Status codes and error response structure must remain identical for the same request inputs.

## In-Scope Endpoints

All routes defined in:

| Module   | Route file (relative to repo root)        | Prefix / group (reference)        |
|----------|-------------------------------------------|-----------------------------------|
| Expense  | `Modules/Expense/routes/api.php`          | `branch-manager/expenses`, etc.   |
| Shift    | `Modules/Shift/routes/api.php`            | `branch-manager` (shift/cashier)  |
| Purchase | `Modules/Purchase/routes/api.php`         | `v1/purchase`                    |

Every endpoint in these files must return the same response (and status) after refactor as before, for the same request.

## Verification

- Run existing feature/API tests before and after refactor; expectations must not change.
- Optionally: snapshot or schema checks on a representative set of endpoints to detect accidental response changes.
- Manual or automated comparison of error responses (validation, not found, unauthorized) for same inputs.

## Out of Scope

- Other modules (Admin, Branch, Inventory, etc.): not part of this refactor’s contract.
- New endpoints or new response fields: not in scope; this feature is refactor-only.

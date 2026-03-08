# Quickstart: Verify Recent Expenses Fix

## Prerequisites

- App running (e.g. `php artisan serve` or deployed URL).
- Authenticated as a branch manager (token or session).

## Verify Recent Expenses Returns Data

1. **Ensure you have expenses**  
   Create at least one expense as the branch manager (any status, e.g. pending) or use existing data.

2. **Call the recent endpoint**  
   - **GET** `{base_url}/api/v1/branch-manager/expenses/recent`  
   - Use the same auth as for the main expenses list (e.g. `Authorization: Bearer <token>`).

3. **Check response**  
   - Status: **200 OK**.  
   - Body: `success: true`, `message` string, `data` array.  
   - If you have expenses: `data` must contain up to 10 items, most recent first, each with `id`, `expense_name`, `expense_type`, `amount`, `date`, `time`, `status`, `created_at`.  
   - If you have no expenses: `data` must be `[]`.

4. **Compare with main list**  
   - **GET** `{base_url}/api/v1/branch-manager/expenses?per_page=20`  
   - Same auth.  
   - The shape of each item in `data` (or first page) must match the shape of each item in the recent endpoint's `data`.

## Optional: Feature test

Run the project's feature tests for the expense module; the recent endpoint should have a test that asserts non-empty `data` when the authenticated user has expenses, and empty `data` when they have none.

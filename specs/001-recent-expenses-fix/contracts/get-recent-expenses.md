# Contract: Get Recent Expenses

## Endpoint

**GET** `/api/v1/branch-manager/expenses/recent`  
(Or as configured: base path + branch-manager/expenses/recent.)

## Authentication / Authorization

- Same as main expenses list: authenticated branch manager only.
- Only expenses for the authenticated user's `branch_manager_id` are returned.

## Request

- No required query parameters.
- Headers: standard auth (e.g. Bearer token or session as per app).

## Response

### Success (200 OK)

- **Body**: JSON with unified success shape.
- **Structure**:
  - `success`: `true`
  - `message`: string (e.g. "Recent expenses retrieved successfully")
  - `data`: array of expense items (same shape as main list), ordered by most recent first, up to 10 items.

**Expense item shape** (each element of `data`):

- `id`: string (UUID)
- `expense_name`: string
- `expense_type`: `{ "value": string, "label": string }`
- `amount`: number
- `date`: string (date)
- `time`: string (time)
- `status`: `{ "value": string, "label": string, "color": string }`
- `created_at`: string (datetime)

### Empty list (200 OK)

When the branch manager has no expenses:

- `success`: `true`
- `message`: string (e.g. "Recent expenses retrieved successfully")
- `data`: `[]`

### Error (4xx/5xx)

- Same error format as rest of API (e.g. 401 Unauthorized, 403 Forbidden).

## Behavior

- Returns the most recent expenses (by `created_at` desc) for the authenticated branch manager.
- Includes all statuses (e.g. pending, approved, rejected).
- Maximum number of items: 10 (or project-defined limit).
- No pagination; single page only.
- Response structure MUST match the main expenses list item shape so clients can reuse the same UI/parsing.

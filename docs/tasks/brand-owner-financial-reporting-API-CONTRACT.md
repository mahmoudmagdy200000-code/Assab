# Brand Owner Financial Reporting — API Contract (Flutter Integration)

> Backend implementation contract for `BrandOwnerFinancialReportingScreen`.
> Use this to wire the repositories. Every shape here is the **actual** server
> response, verified by feature tests (`tests/Feature/BrandOwnerFinancialReportingTest.php`).

---

## 1. Conventions (read first)

### Base URL

```
{API_BASE}/api/brand-owner/financial/...
```

`{API_BASE}` is your environment host. All paths below are relative to
`/api/brand-owner/financial` unless stated otherwise. (`GET /brand-owner/branches`
lives one level up — see §14.)

### Authentication

Every endpoint requires a Sanctum bearer token for a **Brand Owner** account:

```
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json      // for POST bodies
```

A non–brand-owner token receives `403`.

### Unified response envelope

**All** endpoints wrap the payload in this envelope. The "Response `data`"
shapes documented per-endpoint are the contents of the `data` field.

```json
{ "success": true, "message": "…", "data": { /* endpoint payload */ } }
```

List endpoints (saved-tests, saved-scenarios, price-simulator items) return an
**array** in `data`.

### Error envelope

```json
{ "success": false, "message": "…", "errors": { "field": { "messages": ["…"], "first_message": "…", "count": 1 } } }
```

| HTTP | When |
|------|------|
| `401` | Missing/invalid token |
| `403` | Authenticated but not a brand owner |
| `404` | `test_id` / `scenario_id` / `item_id` / branch not found |
| `422` | Validation failed (see `errors`) |

### Money / number types

- All money and percentage values are **doubles** (`500000.0`, `44.44`).
- Counts, `year`, `month` are **ints**.
- `is_*` fields are **bools**.
- No numeric field is ever `null` — absent data returns `0.0`.

### Export & Email conventions

- **Export** endpoints are `POST`, body includes `format_type` = `"PDF"` or `"Excel"`
  (case-insensitive — `"pdf"` / `"excel"` are accepted and normalized).
  Response `data`: `{ "file_url": "https://…" }` (a downloadable absolute URL).
- **Email** endpoints are `POST`, body includes `email`. The report is generated
  and sent asynchronously. Response: `{ "success": true, "message": "Report emailed successfully", "data": null }`.
- Not every report has an email endpoint (**Sales Channel Level 2** and
  **Break-Even** have export only — matches the original spec).

### Enums

| Enum | Values |
|------|--------|
| `format_type` | `"PDF"` or `"Excel"` — case-insensitive (`"pdf"` / `"excel"` also accepted). Where it is optional, a blank string / `null` reads as omitted and falls back to `PDF`. |
| Smart-comparison `type` | `"month"` or `"branch"` |
| Break-even `status` | `very_safe`, `safe`, `at_break_even`, `risk`, `high_risk` |

---

## 2. Profit & Loss Statement

### `GET /profit-and-loss`

| Query param | Type | Required | Notes |
|-------------|------|----------|-------|
| `year` | int | no | defaults to current year |
| `month` | int | no | 1–12, defaults to current month |
| `branch_id` | string | no | omit ⇒ first branch (default). Response echoes the resolved `branch_id` + `branch_name`. |

**Response `data`:**

```json
{
  "year": 2026,
  "month": 6,
  "branch_id": "branch_1",
  "branch_name": "Riyadh Branch",
  "summary": {
    "total_revenue": 500000.0,
    "total_expenses": 350000.0,
    "net_profit": 150000.0,
    "profit_margin_percentage": 30.0,
    "is_profit": true
  },
  "chart": {
    "turnover_total": 500000.0,
    "direct_cost_total": 200000.0,
    "gross_profit": 300000.0,
    "grand_total_cost": 350000.0,
    "profitability_amount": 150000.0,
    "g_and_a_expenses": 80000.0,
    "other_income_expenses": 0.0,
    "net_profit_and_loss": 150000.0
  }
}
```

### `POST /profit-and-loss/export`

Body: `year` (int, req), `month` (int, req, 1–12), `branch_id` (string, opt — omit ⇒ first branch), `format_type` (req).
Response `data`: `{ "file_url": "…" }`.

### `POST /profit-and-loss/email`

Body: `year` (int, req), `month` (int, req), `branch_id` (string, opt — omit ⇒ first branch), `email` (req).

---

## 3. Sales Channel Analysis

### `GET /sales-channel-analysis`

| Query param | Type | Required |
|-------------|------|----------|
| `year` | int | no |
| `month` | int | no |
| `compared_year` | int | no |
| `compared_month` | int | no |
| `branch_id` | string | no |

`branch_id` is optional: omit it and the report defaults to the first branch, echoed
back as the resolved `branch_id` + `branch_name`. Compared period defaults to the
previous month when `compared_*` are omitted.

**Response `data`:**

```json
{
  "year": 2026, "month": 6,
  "compared_year": 2026, "compared_month": 5,
  "month_name": "June",
  "branch_id": "branch_1", "branch_name": "Riyadh Branch",
  "summary": {
    "total_sales": 500000.0,
    "total_profitability": 450000.0,
    "total_profitability_percentage": 90.0,
    "net_profit_percentage": 30.0,
    "profit_margin_percentage": 30.0
  },
  "chart_data": {
    "main_month": 6, "main_year": 2026, "main_value": 500000.0,
    "compared_month": 5, "compared_year": 2026, "compared_value": 450000.0
  }
}
```

### `POST /sales-channel-analysis/export`

Body: `year`, `month`, `compared_year`, `compared_month` (int, req), `branch_id` (string, opt — omit ⇒ first branch), `format_type` (req).

### `POST /sales-channel-analysis/email`

Same as export but `email` (req) instead of `format_type`.

---

## 4. Sales Channel Level 2

### `GET /sales-channel-level2`

Query: `year`, `month`, `compared_year`, `compared_month` (int, optional), `branch_id` (string, optional).

**Response `data`:**

```json
{
  "year": 2026, "month": 6,
  "compared_year": 2026, "compared_month": 5,
  "month_name": "June",
  "total_count": 3,
  "items": [
    {
      "id": "aggregator_1",
      "name": "Delivery App A",
      "image": "https://…/logo.png",
      "sales_amount": 150000.0,
      "percentage_change": 12.5,
      "is_positive": true,
      "comparison_text": "+12.5% vs last month",
      "commission_amount": 7500.0,
      "commission_percentage": 5.0,
      "profitability_amount": 142500.0,
      "profitability_percentage": 95.0,
      "order_count": 1200,
      "average_order_value": 125.0
    }
  ],
  "chart_data": [
    { "month": "2026-01-01T00:00:00.000Z", "value": 100000.0 }
  ]
}
```

`chart_data` holds the trailing **6 months** ending at the main period.
`image` is `""` when the channel has no logo.

### `POST /sales-channel-level2/export`

Body: `year`, `month`, `compared_year`, `compared_month` (int, req), `branch_id` (string, opt — omit ⇒ first branch), `format_type` (req).
**No email endpoint.**

---

## 5. Smart Comparison

### `GET /smart-comparison`

| Query param | Type | Required | Notes |
|-------------|------|----------|-------|
| `year`, `month` | int | no | main period |
| `compared_year`, `compared_month` | int | no | used only when `type=month` |
| `type` | string | **yes** | `month` or `branch` |
| `branch_id` | string | no | omit / send blank ⇒ first branch (default), echoed back |
| `compared_branch_id` | string | no | `type=branch` only — omit ⇒ next branch after `branch_id` |

**Response `data`:**

```json
{
  "year": 2026, "month": 6,
  "compared_year": 2026, "compared_month": 5,
  "month_name": "June", "compared_month_name": "May",
  "type": "month",
  "branch_id": "branch_1", "branch_name": "Riyadh Branch",
  "compared_branch_id": null, "compared_branch_name": null,
  "summary": {
    "total_sales": 500000.0,
    "total_profitability": 300000.0,
    "total_profitability_percentage": 60.0,
    "net_profit_percentage": 30.0,
    "profit_margin_percentage": 30.0
  },
  "chart_data": {
    "main_month": 6, "main_year": 2026, "main_value": 500000.0,
    "compared_month": 5, "compared_year": 2026, "compared_value": 450000.0
  }
}
```

When `type=branch`, `compared_branch_id`/`compared_branch_name` are populated and
`compared_value` is the compared branch's turnover for the same period. With only
one branch in the DB there is nothing to compare against: both compared fields are
`null` and `compared_value` is `0.0`.

### `POST /smart-comparison/export`

Body: `type` (req: `month`|`branch`), `format_type` (req). `year`, `month`,
`compared_year`, `compared_month` (int, opt), `branch_id`, `compared_branch_id`
(string, opt) — same defaults as the GET.

### `POST /smart-comparison/email`

Same as export but `email` (req) instead of `format_type`.

---

## 6. Profit vs Cash Reconciliation

### `GET /profit-vs-cash-reconciliation`

**No query params** — current month, whole brand.

**Response `data`:**

```json
{
  "date_range_label": "June 2026",
  "monthly_profits": 150000.0,
  "current_cash": 95000.0,
  "difference": 55000.0,
  "pending_sales": {
    "total": 20000.0, "paid_amount": 0.0, "shown_in_profits": 20000.0,
    "reason": "Sales recognised in profit but cash not yet collected",
    "items": []
  },
  "rent_paid": { "amount": 10000.0, "paid_amount": 10000.0, "shown_in_profits": 10000.0 },
  "previous_expense_reconciliation": { "amount": 0.0 },
  "advances_fixed_assets": { "amount": 0.0 },
  "zakat_taxes": { "amount": 3000.0, "bought_amount": 3000.0, "used_amount": 0.0 },
  "unused_inventory": { "total": 5000.0, "reason": "Inventory purchased but not yet sold", "items": [] },
  "non_cash_expenses": { "depreciation": 0.0, "others": 0.0, "total": 0.0, "reason": "No non-cash expenses recorded" },
  "supplier_obligations": {
    "total": 8000.0, "reason": "Suppliers not yet paid",
    "items": [ { "name": "Supplier Co.", "amount": 8000.0 } ]
  },
  "accrued_salaries": {
    "total": 6000.0, "reason": "Salaries accrued in profit",
    "items": [ { "name": "Staff Salaries", "amount": 6000.0 } ]
  },
  "expected_cash": 95000.0,
  "final_result_info": [
    { "description": "The difference between monthly profits and current cash is explained by the items above." }
  ]
}
```

`pending_sales.items` / `unused_inventory.items` are currently `[]` (no line-item
source). `supplier_obligations.items` and `accrued_salaries.items` carry
`{ name, amount }`.

### `POST /profit-vs-cash-reconciliation/export`

Body: `format_type` (req). — ### `POST /profit-vs-cash-reconciliation/email` Body: `email` (req).

---

## 7. Break-Even Analysis

### `GET /break-even-analysis`

Query: `year` (int, opt), `month` (int, opt), `branch_id` (string, opt — omit ⇒ first branch, echoed in the response).

**Response `data`:**

```json
{
  "year": 2026, "month": 6,
  "month_name": "June", "previous_month_name": "May",
  "branch_id": "branch_1", "branch_name": "Riyadh Branch",
  "status": "very_safe",
  "current_point_position": 75.0,
  "metrics": { "current_point_x": 500000.0, "current_point_y": 150000.0, "fixed_cost_x": 233333.33, "fixed_cost_y": 0.0 },
  "fixed_costs": { "rent_and_utilities": 50000.0, "salaries": 80000.0, "insurance_and_licenses": 10000.0, "total_fixed": 140000.0 },
  "contribution_margin": { "gross_profit_margin": 60.0, "variable_costs": 200000.0 },
  "formula": { "fixed_costs": 140000.0, "contribution_margin": 0.6, "result": 233333.33 }
}
```

`status` ∈ `very_safe | safe | at_break_even | risk | high_risk` (drives the
strategy-class UI). `current_point_position` is `0–100`.

### `POST /break-even-analysis/export`

Body: `year` (int, req), `month` (int, req, 1–12), `branch_id` (string, opt — omit ⇒ first branch), `format_type` (req).
**No email endpoint.**

---

## 8. Operational Profitability

### `GET /operational-profitability`

**No query params** — current month, whole brand.

**Response `data`:**

```json
{
  "metrics": { "overall_performance": "Good", "overall_score": "B+", "stars_count": 4 },
  "core_indicators": {
    "operating_profit": 150000.0, "operating_profit_change": 12.5, "operating_profit_is_positive": true,
    "ebitda": 180000.0, "ebitda_change": 10.0, "ebitda_is_positive": true,
    "gross_profit_margin": 60.0, "gross_profit_margin_change": 2.5, "gross_profit_margin_is_positive": true,
    "return_on_sales": 25.0, "return_on_sales_label": "Excellent"
  },
  "efficiency": {
    "productivity_per_employee": 120000.0, "productivity_per_employee_change": 5.0, "productivity_is_positive": true,
    "labor_cost": 80000.0, "labor_cost_label": "16% of revenue",
    "inventory_turnover": 8.5, "inventory_turnover_unit": "times/year",
    "average_transaction_value": 125.0,
    "space_efficiency": 0.0, "space_efficiency_unit": "SAR/m²"
  },
  "benchmark": {
    "profit_margin_your_restaurant": 25.0, "profit_margin_industry": 20.0,
    "labor_cost_your_restaurant": 16.0, "labor_cost_industry": 22.0,
    "inventory_turnover_your_restaurant": 8.5, "inventory_turnover_industry": 6.0
  }
}
```

`return_on_sales_label` ∈ `Excellent | Good | Fair | Poor`. `overall_score` ∈
`A+ | A | B+ | B | C | D`. `stars_count` `1–5`.

### `POST /operational-profitability/export`

Body: `format_type` (req). — ### `POST /operational-profitability/email` Body: `email` (req),
`format_type` (opt — omitted / blank / `null` ⇒ `PDF`).

---

## 9. Menu Engineering

### `GET /menu-engineering`

Query: `year`, `month`, `compared_year`, `compared_month` (int, opt), `branch_id` (string, opt — omit ⇒ first branch, echoed in the response).

**Response `data`:**

```json
{
  "year": 2026, "month": 6, "month_name": "June",
  "compared_year": 2026, "compared_month": 5, "compared_month_name": "May",
  "branch_id": "branch_1", "branch_name": "Riyadh Branch",
  "puzzles":    { "items_count": 8,  "percentage": 20.0, "is_high_profitability": false },
  "stars":      { "items_count": 12, "percentage": 30.0, "is_high_profitability": true },
  "dogs":       { "items_count": 6,  "percentage": 15.0, "is_high_profitability": false },
  "workhorses": { "items_count": 14, "percentage": 35.0, "is_high_profitability": true },
  "puzzles_category": {
    "items_count": 8, "percentage": 20.0, "amount": 80000.0, "amount_percentage": 16.0,
    "items": [ { "category": "Burgers", "sales_percentage": 8.0, "profit_percentage": 5.0 } ]
  },
  "stars_category":     { "items_count": 12, "percentage": 30.0, "amount": 180000.0, "amount_percentage": 36.0, "items": [ … ] },
  "dogs_category":      { "items_count": 6,  "percentage": 15.0, "amount": 40000.0,  "amount_percentage": 8.0,  "items": [ … ] },
  "workhorses_category":{ "items_count": 14, "percentage": 35.0, "amount": 200000.0, "amount_percentage": 40.0, "items": [ … ] }
}
```

Quadrant mapping (per spec): `stars` = high popularity + high profitability;
`puzzles` = high popularity + low profitability; `workhorses` = low popularity +
high profitability; `dogs` = low popularity + low profitability.

### `POST /menu-engineering/export`

Body: `format_type` (req). `year`, `month`, `compared_year`, `compared_month`
(int, opt — default current month vs previous), `branch_id` (string, opt — omit ⇒ first branch).

### `POST /menu-engineering/email`

Same as export but `email` (req) instead of `format_type`.

---

## 10. Item Test

### `POST /item-test/submit`  → **HTTP 201**

Body:

| Field | Type | Required |
|-------|------|----------|
| `branch_id` | string | no — omit ⇒ first branch, stored on the test |
| `item_name` | string | yes |
| `expected_selling_price` | number | yes (≥0) |
| `production_cost` | number | yes (≥0) |
| `expected_sales` | integer | yes (≥0) |
| `expected_growth` | number | yes |

**Response `data`:**

```json
{
  "id": "test_1",
  "profit_margin": 44.44,
  "profit_margin_label": "44.44%",
  "expected_monthly_profit": 10000.0,
  "expected_classification": "Star",
  "menu_impact": "High profitability item recommended for addition",
  "date": "2026-06-23T10:30:00.000Z",
  "branch_name": "Riyadh Branch",
  "item_name": "New Chicken Burger",
  "expected_selling_price": 45.0,
  "production_cost": 25.0,
  "expected_sales": 500,
  "expected_growth": 10.0
}
```

`expected_classification` ∈ `Star | Workhorse | Puzzle | Dog`.

### `GET /item-test/saved-tests`

Response `data`: **array** of the same object shape (latest first, max 50, only
the caller's own tests).

### `POST /item-test/export`

Body: `test_id` (string, req), `format_type` (req). `404` if the test isn't the caller's.

### `POST /item-test/email`

Body: `test_id` (string, req), `email` (req).

---

## 11. Price Simulator

### `GET /price-simulator/items`

No params. Response `data`: **array**:

```json
[ { "id": "item_1", "name": "Chicken Burger" }, { "id": "item_2", "name": "Beef Burger" } ]
```

### `GET /price-simulator/item-info`

Query: `branch_id` (string, req), `item_id` (string, req). `404` if the item isn't in that branch.

**Response `data`:**

```json
{
  "item_id": "item_1",
  "item_name": "Chicken Burger",
  "current_selling_price": 40.0,
  "production_cost": 20.0,
  "current_monthly_sales": 800,
  "expected_growth_percentage": 5.0
}
```

### `POST /price-simulator/simulate`  → **HTTP 201**

Body:

| Field | Type | Required |
|-------|------|----------|
| `branch_id` | string | yes |
| `item_id` | string | yes |
| `change_price` | number | yes (≥0) |
| `expected_growth_decline` | number | yes (can be negative) |

**Response `data`:**

```json
{
  "new_price": 45.0,
  "expected_sales": 720,
  "expected_sales_change_percentage": -10.0,
  "new_unit_profit": 25.0,
  "new_unit_profit_change_percentage": 25.0,
  "new_monthly_profit": 18000.0,
  "profit_change": 2000.0,
  "profit_change_percentage": 12.5,
  "date": "2026-06-23T10:30:00.000Z",
  "branch_name": "Riyadh Branch",
  "item_name": "Chicken Burger",
  "current_selling_price": 40.0,
  "production_cost": 20.0,
  "current_monthly_sales": 800,
  "expected_growth_percentage": -10.0
}
```

### `GET /price-simulator/saved-scenarios`

Response `data`: **array**, latest first, max 50, caller's own. Each entry exposes
the scenario `id` (pass it as `scenario_id` to export/email) plus a nested
`details` object holding the full simulation shape:

```json
[
  {
    "id": "scenario_1",
    "details": {
      "new_price": 45.0,
      "expected_sales": 720,
      "expected_sales_change_percentage": -10.0,
      "new_unit_profit": 25.0,
      "new_unit_profit_change_percentage": 25.0,
      "new_monthly_profit": 18000.0,
      "profit_change": 2000.0,
      "profit_change_percentage": 12.5,
      "date": "2026-06-23T10:30:00.000Z",
      "branch_name": "Riyadh Branch",
      "item_name": "Chicken Burger",
      "current_selling_price": 40.0,
      "production_cost": 20.0,
      "current_monthly_sales": 800,
      "expected_growth_percentage": -10.0
    }
  }
]
```

### `POST /price-simulator/export`

Body: `scenario_id` (string, req), `format_type` (req). `404` if not the caller's.

### `POST /price-simulator/email`

Body: `scenario_id` (string, req), `email` (req).

---

## 12. Get Branches (filter dropdown)

### `GET /brand-owner/branches`

> Note: one level up — path is `/api/brand-owner/branches`, **not** under `/financial`.

**Response `data`:**

```json
{ "branches": [ { "id": "branch_1", "name": "Riyadh Branch", "managerName": "…" } ] }
```

Use `id` + `name`; `managerName` is extra and can be ignored.

---

## 13. Endpoint → route quick map

| # | Method | Path (under `/api`) |
|---|--------|---------------------|
| 1 | GET  | `brand-owner/financial/profit-and-loss` |
| 2 | POST | `brand-owner/financial/profit-and-loss/export` |
| 3 | POST | `brand-owner/financial/profit-and-loss/email` |
| 4 | GET  | `brand-owner/financial/sales-channel-analysis` |
| 5 | POST | `brand-owner/financial/sales-channel-analysis/export` |
| 6 | POST | `brand-owner/financial/sales-channel-analysis/email` |
| 7 | GET  | `brand-owner/financial/sales-channel-level2` |
| 8 | POST | `brand-owner/financial/sales-channel-level2/export` |
| 9 | GET  | `brand-owner/financial/smart-comparison` |
| 10 | POST | `brand-owner/financial/smart-comparison/export` |
| 11 | POST | `brand-owner/financial/smart-comparison/email` |
| 12 | GET  | `brand-owner/financial/profit-vs-cash-reconciliation` |
| 13 | POST | `brand-owner/financial/profit-vs-cash-reconciliation/export` |
| 14 | POST | `brand-owner/financial/profit-vs-cash-reconciliation/email` |
| 15 | GET  | `brand-owner/financial/break-even-analysis` |
| 16 | POST | `brand-owner/financial/break-even-analysis/export` |
| 17 | GET  | `brand-owner/financial/operational-profitability` |
| 18 | POST | `brand-owner/financial/operational-profitability/export` |
| 19 | POST | `brand-owner/financial/operational-profitability/email` |
| 20 | GET  | `brand-owner/financial/menu-engineering` |
| 21 | POST | `brand-owner/financial/menu-engineering/export` |
| 22 | POST | `brand-owner/financial/menu-engineering/email` |
| 23 | POST | `brand-owner/financial/item-test/submit` |
| 24 | GET  | `brand-owner/financial/item-test/saved-tests` |
| 25 | POST | `brand-owner/financial/item-test/export` |
| 26 | POST | `brand-owner/financial/item-test/email` |
| 27 | GET  | `brand-owner/financial/price-simulator/items` |
| 28 | GET  | `brand-owner/financial/price-simulator/item-info` |
| 29 | POST | `brand-owner/financial/price-simulator/simulate` |
| 30 | GET  | `brand-owner/financial/price-simulator/saved-scenarios` |
| 31 | POST | `brand-owner/financial/price-simulator/export` |
| 32 | POST | `brand-owner/financial/price-simulator/email` |
| 33 | GET  | `brand-owner/branches` |

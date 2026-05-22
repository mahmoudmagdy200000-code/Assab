# Brand Owner Home API

## Screen

`BrandOwnerHomeScreen`

This screen shows the brand owner home dashboard for:

Get any default branch (first branch)

- Set year and month filter as default
- if send branch id only set month and year filter as default
- Branch selection and filtering
- Invoice and expense summaries
- Quick action buttons (inventory, bank transfer, cash handover, escalation requests, reports)
- Expense trends with daily/weekly/monthly granularity
- Chart visualization of expense data

---

## Repository Interface

`BrandOwnerHomeRepository`

| Method                                                             | Description                                     |
| ------------------------------------------------------------------ | ----------------------------------------------- |
| `getBrandOwnerBranches()`                                          | Fetch all branches for the brand owner          |
| `getBrandOwnerHomeDashboard({branchId, month, year, granularity})` | Fetch home dashboard data with optional filters |

---

## 1. Get Brand Owner Branches

#### GET `/brand-owner/dashboard/branches`

Returns the list of branches for the brand owner.
No Pagination

- **Response Object:** `List<BrandOwnerBranchModel>`

##### Response Shape

```json
[
  {
    "id": "string",
    "name": "string",
    "image_url": "string",
    "opening_hours": "string",
    "google_map_url": "string"
  }
]
```

---

## 2. Get Brand Owner Home Dashboard

#### GET `/brand-owner/dashboard`

Returns the home dashboard data including invoice/expense summaries and trend chart data.

- **Query Parameters:** (all optional)
- **Response Object:** `BrandOwnerHomeDataModel`

##### Query Parameters

| Field       | Type   | Required | Description                                    |
| ----------- | ------ | -------- | ---------------------------------------------- |
| branch_id   | string | optional | Branch identifier                              |
| month       | number | optional | Month number (1-12)                            |
| year        | number | optional | Year (e.g., 2026)                              |
| granularity | string | optional | Data granularity: `daily`, `weekly`, `monthly` |

##### Response Shape

```json
{
  "approved_invoices": 12,
  "total_invoice_amount": 45000.0,
  "pending_invoices": 3,
  "approved_expenses": 8,
  "total_expense_amount": 28500.0,
  "pending_expenses": 5,
  "granularity_chart_data": {
    "daily": {
      "trend_period": "June 5 (Week 1)",
      "approved": 5,
      "total_amount": 2000.0,
      "increase_percentage": 12.5,
      "chart_data": [10000.0, 25000.0, 50000.0, 75000.0]
    },
    "weekly": {
      "trend_period": "June 2026 (Week 1-4)",
      "approved": 18,
      "total_amount": 9500.0,
      "increase_percentage": 8.0,
      "chart_data": [20000.0, 40000.0, 30000.0, 60000.0, 80000.0]
    },
    "monthly": {
      "trend_period": "June 2026",
      "approved": 65,
      "total_amount": 38000.0,
      "increase_percentage": 15.3,
      "chart_data": [15000.0, 30000.0, 45000.0, 60000.0]
    }
  },
  "response_month": 6,
  "response_year": 2026,
  "response_branch_name": "Branch 1"
}
```

---

## Models Reference

### BrandOwnerBranchModel

| Field        | Type      | Description                         |
| ------------ | --------- | ----------------------------------- |
| id           | `String`  | Branch identifier                   |
| name         | `String`  | Branch name                         |
| imageUrl     | `String?` | Branch image URL                    |
| openingHours | `String?` | Branch opening hours                |
| googleMapUrl | `String?` | Google Maps URL for branch location |

### BrandOwnerHomeDataModel

| Field                | Type                                | Description                                                    |
| -------------------- | ----------------------------------- | -------------------------------------------------------------- |
| approvedInvoices     | `int`                               | Number of approved invoices                                    |
| totalInvoiceAmount   | `double`                            | Total invoice amount                                           |
| pendingInvoices      | `int`                               | Number of pending invoices                                     |
| approvedExpenses     | `int`                               | Number of approved expenses                                    |
| totalExpenseAmount   | `double`                            | Total expense amount                                           |
| pendingExpenses      | `int`                               | Number of pending expenses                                     |
| granularityChartData | `Map<String, GranularityTrendData>` | Trend data keyed by granularity (`daily`, `weekly`, `monthly`) |
| responseMonth        | `int`                               | Response month number                                          |
| responseYear         | `int`                               | Response year                                                  |
| responseBranchName   | `String`                            | Branch name from response                                      |

### GranularityTrendData

| Field              | Type           | Description                            |
| ------------------ | -------------- | -------------------------------------- |
| trendPeriod        | `String`       | Trend period label (e.g., "June 2026") |
| approved           | `int`          | Number of approved items               |
| totalAmount        | `double`       | Total amount for the period            |
| increasePercentage | `double`       | Percentage increase from last period   |
| chartData          | `List<double>` | Chart data points for visualization    |

---

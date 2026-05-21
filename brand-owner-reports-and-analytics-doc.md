# Brand Owner Reports and Analytics API

## Screen

`BrandOwnerReportsAndAnalyticsScreen`

This screen shows the brand owner reports and analytics for:

- Expense reports with detailed insights
- Custody reports with branch account summaries
- Export history with download capabilities
- Report filtering and export options

---

## Repository Interface

`BrandOwnerReportsAndAnalyticsRepository`

| Method                                                              | Description                                        |
| ------------------------------------------------------------------- | -------------------------------------------------- |
| `getReportsAndAnalytics()`                                          | Fetch all available reports and export history     |
| `getExpenseReportDetails({reportId, filters})`                      | Fetch expense report details with optional filters |
| `getCustodyReportDetails({reportId})`                               | Fetch custody report details by branch             |
| `exportCustodyReport({year, monthNumber, formatType})`              | Export custody report in PDF or Excel format       |
| `exportExpenseReport({year, monthNumber, formatType, expenseType})` | Export expense report in PDF or Excel format       |

---

## 1. Get Reports and Analytics

#### GET `/brand-owner/reports-and-analytics`

Returns the list of available reports and export history.

- **Response Object:** `BrandOwnerReportsAndAnalyticsResponse`

##### Response Shape

```json
{
  "reports": [
    {
      "id": "string",
      "type": "expenses",
      "period_label": "2026-05-16T08:30:00.000Z",
      "status_label": "completed"
    }
  ],
  "export_history": [
    {
      "id": "string",
      "title": "string",
      "created_at_label": "2026-05-16T10:15:00.000Z",
      "download_url": "string",
      "type": "pdf"
    }
  ]
}
```

##### Report Types

| Type       | Description    | Strategy Class               |
| ---------- | -------------- | ---------------------------- |
| `expenses` | Expense report | `ExpensesReportTypeStrategy` |
| `custody`  | Custody report | `CustodyReportTypeStrategy`  |

---

## 2. Get Expense Report Details

#### GET `/brand-owner/reports/expense/{reportId}`

Returns full details for an expense report including summary, payment methods, suppliers, ratios, branch comparisons, and cash transfer logs.

- **Path Parameters:** `reportId` (string, required)
- **Query Parameters:** `BrandOwnerExpenseReportFilterRequest` (optional)
- **Response Object:** `BrandOwnerExpenseReportDetailsResponse`

##### Query Parameters (Filters)

| Field     | Type   | Required | Rules                           |
| --------- | ------ | -------- | ------------------------------- |
| type      | string | optional | One of: `quick_cash`, `invoice` |
| status    | string | optional | Report status filter            |
| month     | number | optional | Month number (1-12)             |
| year      | number | optional | Year (e.g., 2026)               |
| branch_id | string | optional | Branch identifier               |

##### Response Shape

```json
{
  "summary": {
    "branch": {
      "id": "string",
      "name": "string",
      "image_url": "string"
    },
    "period_label": "June 2026",
    "total_requests": 45,
    "total_amount": 125000.0,
    "trend_percent": "+12.5%",
    "trend_label": "vs last month",
    "trend_is_up": true
  },
  "payment_methods": [
    {
      "method": "cash_handover",
      "count": 25,
      "amount": 75000.0
    }
  ],
  "top_suppliers": [
    {
      "name": "string",
      "amount": 45000.0
    }
  ],
  "expense_ratios": [
    {
      "type": "tax",
      "count": 30,
      "amount": 90000.0
    }
  ],
  "branch_comparisons": [
    {
      "name": "Riyadh Branch",
      "amount": 50000.0,
      "max_amount": 125000.0
    }
  ],
  "cash_transfer_log": {
    "total_cash_in": 150000.0,
    "total_cash_out": 25000.0,
    "count": 15,
    "logs": [
      {
        "method": "cash_handover",
        "sub_label": "string",
        "branch": "Riyadh Branch",
        "date_time": "2026-05-16T10:15:00.000Z",
        "amount": 5000.0
      }
    ]
  },
  "quick_summary": {
    "amount": 15000.0,
    "trend_percent": "+8.3%",
    "trend_label": "vs last month",
    "trend_is_up": true
  },
  "default_branch": {
    "id": "string",
    "name": "string",
    "image_url": "string"
  },
  "filters": {
    "type": "quick_cash",
    "status": "completed",
    "month": 6,
    "year": 2026,
    "branch_id": "string",
    "branch_name": "Riyadh Branch"
  }
}
```

---

## 3. Get Custody Report Details

#### GET `/brand-owner/reports/custody/{reportId}`

Returns full details for a custody report including branch accounts and balance summaries.

- **Path Parameters:** `reportId` (string, required)
- **Response Object:** `BrandOwnerCustodyReportDetailsResponse`

##### Response Shape

```json
{
  "period_label": "June Custody Summary",
  "branches": [
    {
      "image_url": "https://example.com/branch1.png",
      "name": "Branch 1",
      "branch": "Downtown",
      "current_balance": 15000.0,
      "amounts": {
        "month_name": "June",
        "month_opening_balance": 10000.0,
        "total_cash_in": 7000.0,
        "total_cash_out": 2000.0,
        "current_balance": 15000.0
      }
    },
    {
      "image_url": "https://example.com/branch2.png",
      "name": "Branch 2",
      "branch": "Uptown",
      "current_balance": 12000.0,
      "amounts": {
        "month_name": "June",
        "month_opening_balance": 8000.0,
        "total_cash_in": 6000.0,
        "total_cash_out": 2000.0,
        "current_balance": 12000.0
      }
    }
  ]
}
```

---

## 4. Export Expense Report

#### POST `/brand-owner/reports/expense/export`

Exports an expense report in the specified format.

- **Request Body:** `ExportExpenseReportRequest`
- **Response Object:** `BrandOwnerExportReportResponse`

##### Request Body

| Field        | Type   | Required | Rules                           |
| ------------ | ------ | -------- | ------------------------------- |
| expense_type | string | required | One of: `quick_cash`, `invoice` |
| year         | number | required | Year (e.g., 2026)               |
| month_number | number | required | Month number (1-12)             |
| format_type  | string | required | One of: `PDF`, `Excel`          |

##### Request Shape

```json
{
  "expense_type": "quick_cash",
  "year": 2026,
  "month_number": 6,
  "format_type": "PDF"
}
```

##### Response Shape

```json
{
  "data": {
    "file_url": "https://example.com/expense_report.pdf"
  }
}
```

---

## 5. Export Custody Report

#### POST `/brand-owner/reports/custody/export`

Exports a custody report in the specified format.

- **Request Body:** `ExportCustodyReportRequest`
- **Response Object:** `BrandOwnerExportReportResponse`

##### Request Body

| Field        | Type   | Required | Rules                  |
| ------------ | ------ | -------- | ---------------------- |
| year         | number | required | Year (e.g., 2026)      |
| month_number | number | required | Month number (1-12)    |
| format_type  | string | required | One of: `PDF`, `Excel` |

##### Request Shape

```json
{
  "year": 2026,
  "month_number": 6,
  "format_type": "PDF"
}
```

##### Response Shape

```json
{
  "data": {
    "file_url": "https://example.com/custody_report.pdf"
  }
}
```

---

## Models Reference

### BrandOwnerReportsAndAnalyticsResponse

| Field          | Type                                 | Description            |
| -------------- | ------------------------------------ | ---------------------- |
| reports        | `List<BrandOwnerReportModel>`        | Available reports list |
| export_history | `List<BrandOwnerExportHistoryModel>` | Export history list    |

### BrandOwnerReportModel

| Field        | Type                            | Description          |
| ------------ | ------------------------------- | -------------------- |
| id           | `String`                        | Report identifier    |
| period_label | `String`                        | Report period label  |
| status_label | `String`                        | Report status label  |
| type         | `BrandOwnerReportsTypeStrategy` | Report type strategy |

### BrandOwnerExportHistoryModel

| Field            | Type     | Description            |
| ---------------- | -------- | ---------------------- |
| id               | `String` | Export identifier      |
| title            | `String` | Export file title      |
| created_at_label | `String` | Creation date label    |
| download_url     | `String` | Download URL           |
| type             | `String` | File type (pdf, excel) |

### BrandOwnerExpenseReportDetailsResponse

| Field              | Type                                        | Description                     |
| ------------------ | ------------------------------------------- | ------------------------------- |
| summary            | `ExpenseReportSummaryModel`                 | Report summary with branch info |
| payment_methods    | `List<ExpenseReportPaymentMethodModel>`     | Payment method breakdown        |
| top_suppliers      | `List<ExpenseReportSupplierModel>`          | Top 5 suppliers by amount       |
| expense_ratios     | `List<ExpenseReportRatioModel>`             | Tax vs non-tax ratio            |
| branch_comparisons | `List<ExpenseReportBranchComparisonModel>`  | Branch comparison data          |
| cash_transfer_log  | `ExpenseReportCashTransferLogModel`         | Cash transfer and payment log   |
| quick_summary      | `ExpenseReportQuickSummaryModel`            | Quick expenses footer summary   |
| default_branch     | `ExpenseReportBranchModel`                  | Default branch info             |
| filters            | `BrandOwnerExpenseReportDetailsFilterModel` | Applied filters                 |

### BrandOwnerCustodyReportDetailsResponse

| Field        | Type                             | Description            |
| ------------ | -------------------------------- | ---------------------- |
| period_label | `String`                         | Report period label    |
| branches     | `List<CustodyReportBranchModel>` | Branch custody details |

### CustodyReportBranchModel

| Field           | Type                       | Description             |
| --------------- | -------------------------- | ----------------------- |
| image_url       | `String`                   | Branch image URL        |
| name            | `String`                   | Branch name             |
| branch          | `String`                   | Branch location         |
| current_balance | `double`                   | Current custody balance |
| amounts         | `CustodyReportAmountModel` | Amount breakdown        |

### CustodyReportAmountModel

| Field                 | Type     | Description                   |
| --------------------- | -------- | ----------------------------- |
| month_name            | `String` | Month name                    |
| month_opening_balance | `double` | Opening balance for the month |
| total_cash_in         | `double` | Total cash received           |
| total_cash_out        | `double` | Total cash disbursed          |
| current_balance       | `double` | Current balance               |

### BrandOwnerExportReportResponse

| Field    | Type     | Description                 |
| -------- | -------- | --------------------------- |
| file_url | `String` | Generated file download URL |

---

## Enums Reference

### BrandOwnerExpenseReportType

| Value        | Description             |
| ------------ | ----------------------- |
| `quick_cash` | Quick cash expenses     |
| `invoice`    | Single invoice expenses |

### BrandOwnerExpenseReportStatus

| Value                                           | Description |
| ----------------------------------------------- | ----------- |
| Various status values as defined by the backend |

### ExpensesRequestsTaxNonTaxEnum

| Value     | Description     |
| --------- | --------------- |
| `tax`     | Tax invoice     |
| `non_tax` | Non-tax invoice |

---

## 1. Get Branches

#### GET `/brand-owner/branches`

Returns the list of branches for the brand owner.
No Pagination

- **Response Object:** `BrandOwnerBranchesResponse`

##### Response Shape

```json
{
  "branches": [
    { "id": "id", "name": "name", "managerName": "managerName" },
    { "id": "id", "name": "name", "managerName": "managerName" }
  ]
}
```

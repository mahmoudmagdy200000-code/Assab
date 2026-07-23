# Brand Owner Financial Reporting API

## Screen

`BrandOwnerFinancialReportingScreen`

This screen serves as the hub for the brand owner financial reporting suite, grouping reports into
three categories:

- **Core Reports:** Profit & Loss Statement, Sales Channel Analysis, Smart Comparisons
- **Specialized Reports:** Profit vs Cash Reconciliation, Break-Even Analysis, Operational
  Profitability, Menu Engineering
- **Menu Engineering Actions:** Item Test, Pricing Simulator

---

## Repository Interfaces

### `BrandOwnerProfitAndLossStatementRepository`

| Method                                                              | Description                                          |
|---------------------------------------------------------------------|------------------------------------------------------|
| `getProfitAndLossStatement({year, month, branchId})`                | Fetch profit & loss statement with summary and chart |
| `exportProfitAndLossStatement({year, month, branchId, formatType})` | Export P&L in PDF or Excel format                    |
| `getBranches()`                                                     | Fetch available branches for filtering               |
| `emailProfitAndLossStatement({year, month, branchId, email})`       | Email P&L report                                     |

### `BrandOwnerSalesChannelAnalysisRepository`

| Method                                                                                         | Description                                       |
|------------------------------------------------------------------------------------------------|---------------------------------------------------|
| `getSalesChannelAnalysis({year, month, comparedYear, comparedMonth, branchId})`                | Fetch sales channel analysis with comparison data |
| `exportSalesChannelAnalysis({year, month, comparedYear, comparedMonth, branchId, formatType})` | Export sales channel analysis                     |
| `emailSalesChannelAnalysis({year, month, comparedYear, comparedMonth, branchId, email})`       | Email sales channel analysis                      |

### `BrandOwnerSalesChannelLevel2Repository`

| Method                                                                                       | Description                         |
|----------------------------------------------------------------------------------------------|-------------------------------------|
| `getSalesChannelLevel2({year, month, comparedYear, comparedMonth, branchId})`                | Fetch detailed level-2 channel data |
| `exportSalesChannelLevel2({year, month, comparedYear, comparedMonth, branchId, formatType})` | Export level-2 channel data         |

### `BrandOwnerSmartComparisonRepository`

| Method                                                                                                            | Description                                         |
|-------------------------------------------------------------------------------------------------------------------|-----------------------------------------------------|
| `getSmartComparison({year, month, comparedYear, comparedMonth, type, branchId, comparedBranchId})`                | Fetch month-to-month or branch-to-branch comparison |
| `exportSmartComparison({year, month, comparedYear, comparedMonth, type, branchId, comparedBranchId, formatType})` | Export smart comparison                             |
| `emailSmartComparison({year, month, comparedYear, comparedMonth, type, branchId, comparedBranchId, email})`       | Email smart comparison                              |

### `BrandOwnerProfitVsCashReconciliationRepository`

| Method                                           | Description                                               |
|--------------------------------------------------|-----------------------------------------------------------|
| `getProfitVsCashReconciliation()`                | Fetch reconciliation between book profits and actual cash |
| `exportProfitVsCashReconciliation({formatType})` | Export reconciliation                                     |
| `emailProfitVsCashReconciliation({email})`       | Email reconciliation                                      |

### `BrandOwnerBreakEvenAnalysisRepository`

| Method                                                         | Description                                        |
|----------------------------------------------------------------|----------------------------------------------------|
| `getBreakEvenAnalysis({year, month, branchId})`                | Fetch break-even analysis with metrics and formula |
| `exportBreakEvenAnalysis({year, month, branchId, formatType})` | Export break-even analysis                         |

### `BrandOwnerOperationalProfitabilityRepository`

| Method                                         | Description                                     |
|------------------------------------------------|-------------------------------------------------|
| `getOperationalProfitability()`                | Fetch operational profitability with benchmarks |
| `exportOperationalProfitability({formatType})` | Export operational profitability                |
| `emailOperationalProfitability({email})`       | Email operational profitability                 |

### `BrandOwnerMenuEngineeringRepository`

| Method                                                                              | Description                                                   |
|-------------------------------------------------------------------------------------|---------------------------------------------------------------|
| `getMenuEngineering({year, month, comparedYear, comparedMonth, branchId})`          | Fetch menu engineering matrix (stars/puzzles/dogs/workhorses) |
| `exportMenuEngineering({year, month, comparedYear, comparedMonth, branchId})`       | Export menu engineering                                       |
| `emailMenuEngineering({year, month, comparedYear, comparedMonth, branchId, email})` | Email menu engineering                                        |

### `ItemTestRepository`

| Method                                                                                                              | Description                       |
|---------------------------------------------------------------------------------------------------------------------|-----------------------------------|
| `submitTest({branchId, branchName, itemName, expectedSellingPrice, productionCost, expectedSales, expectedGrowth})` | Submit an item profitability test |
| `getSavedTests()`                                                                                                   | Fetch saved item tests            |
| `saveTest(result)`                                                                                                  | Save an item test result          |
| `exportTest({testId, formatType})`                                                                                  | Export item test                  |
| `emailTest({testId, email})`                                                                                        | Email item test                   |

### `PriceSimulatorRepository`

| Method                                                                     | Description                          |
|----------------------------------------------------------------------------|--------------------------------------|
| `getItems()`                                                               | Fetch available items for simulation |
| `getItemInfo({branchId, itemId})`                                          | Fetch item pricing and cost details  |
| `submitSimulation({branchId, itemId, changePrice, expectedGrowthDecline})` | Run a price change simulation        |
| `getSavedScenarios()`                                                      | Fetch saved simulation scenarios     |
| `saveScenario(result)`                                                     | Save a simulation scenario           |
| `exportScenario({scenarioId, formatType})`                                 | Export a scenario                    |
| `emailScenario({scenarioId, email})`                                       | Email a scenario                     |

---

## 1. Profit & Loss Statement

#### GET `/brand-owner/financial/profit-and-loss`

Returns the profit and loss statement with summary and chart breakdown.

- **Query Parameters:** `year` (int, optional), `month` (int, optional), `branch_id` (string,
  optional)
- **Response Object:** `ProfitAndLossStatementModel`

##### Response Shape

```json
{
  "year": 2026,
  "month": 6,
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
    "other_income_expenses": 20000.0,
    "net_profit_and_loss": 150000.0
  }
}
```

---

## 2. Sales Channel Analysis

#### GET `/brand-owner/financial/sales-channel-analysis`

Returns sales channel performance analysis with month-over-month comparison.

- **Query Parameters:** `year` (int, optional), `month` (int, optional), `compared_year` (int,
  optional), `compared_month` (int, optional), `branch_id` (string, required)
- **Response Object:** `SalesChannelAnalysisModel`

##### Response Shape

```json
{
  "year": 2026,
  "month": 6,
  "compared_year": 2026,
  "compared_month": 5,
  "month_name": "June",
  "branch_id": "branch_1",
  "branch_name": "Riyadh Branch",
  "summary": {
    "total_sales": 500000.0,
    "total_profitability": 150000.0,
    "total_profitability_percentage": 30.0,
    "net_profit_percentage": 25.0,
    "profit_margin_percentage": 30.0
  },
  "chart_data": {
    "main_month": 6,
    "main_year": 2026,
    "main_value": 500000.0,
    "compared_month": 5,
    "compared_year": 2026,
    "compared_value": 450000.0
  }
}
```

---

## 3. Sales Channel Level 2

#### GET `/brand-owner/financial/sales-channel-level2`

Returns detailed level-2 channel breakdown with individual items.

- **Query Parameters:** `year` (int, optional), `month` (int, optional), `compared_year` (int,
  optional), `compared_month` (int, optional), `branch_id` (string, optional)
- **Response Object:** `SalesChannelLevel2Model`

##### Response Shape

```json
{
  "year": 2026,
  "month": 6,
  "compared_year": 2026,
  "compared_month": 5,
  "month_name": "June",
  "total_count": 25,
  "items": [
    {
      "id": "item_1",
      "name": "Delivery App A",
      "image": "https://example.com/logo.png",
      "sales_amount": 150000.0,
      "percentage_change": 12.5,
      "is_positive": true,
      "comparison_text": "+12.5% vs last month",
      "commission_amount": 7500.0,
      "commission_percentage": 5.0,
      "profitability_amount": 45000.0,
      "profitability_percentage": 30.0,
      "order_count": 1200,
      "average_order_value": 125.0
    }
  ],
  "chart_data": [
    {
      "month": "2026-01-01T00:00:00.000Z",
      "value": 100000.0
    }
  ]
}
```

---

## 4. Smart Comparisons

#### GET `/brand-owner/financial/smart-comparison`

Returns month-to-month or branch-to-branch financial comparison.

- **Query Parameters:** `year` (int, optional), `month` (int, optional), `compared_year` (int,
  optional), `compared_month` (int, optional), `type` (string, required — `month` or `branch`),
  `branch_id` (string, required), `compared_branch_id` (string, optional)
- **Response Object:** `SmartComparisonModel`

##### Response Shape

```json
{
  "year": 2026,
  "month": 6,
  "compared_year": 2026,
  "compared_month": 5,
  "month_name": "June",
  "compared_month_name": "May",
  "type": "month",
  "branch_id": "branch_1",
  "branch_name": "Riyadh Branch",
  "compared_branch_id": "branch_2",
  "compared_branch_name": "Jeddah Branch",
  "summary": {
    "total_sales": 500000.0,
    "total_profitability": 150000.0,
    "total_profitability_percentage": 30.0,
    "net_profit_percentage": 25.0,
    "profit_margin_percentage": 30.0
  },
  "chart_data": {
    "main_month": 6,
    "main_year": 2026,
    "main_value": 500000.0,
    "compared_month": 5,
    "compared_year": 2026,
    "compared_value": 450000.0
  }
}
```

---

## 5. Profit vs Cash Reconciliation

#### GET `/brand-owner/financial/profit-vs-cash-reconciliation`

Returns a detailed reconciliation breaking down the difference between book profits and actual cash
position.

- **Response Object:** `ProfitVsCashReconciliationModel`

##### Response Shape

```json
{
  "date_range_label": "June 2026",
  "monthly_profits": 150000.0,
  "current_cash": 95000.0,
  "difference": 55000.0,
  "pending_sales": {
    "total": 20000.0,
    "paid_amount": 15000.0,
    "shown_in_profits": 20000.0,
    "reason": "Pending sales collected after month end",
    "items": [
      {
        "name": "Invoice #1234",
        "image": "",
        "amount": 5000.0,
        "type": "credit"
      }
    ]
  },
  "rent_paid": {
    "amount": 10000.0,
    "paid_amount": 10000.0,
    "shown_in_profits": 10000.0
  },
  "previous_expense_reconciliation": {
    "amount": 5000.0
  },
  "advances_fixed_assets": {
    "amount": 8000.0
  },
  "zakat_taxes": {
    "amount": 3000.0,
    "bought_amount": 3000.0,
    "used_amount": 0.0
  },
  "unused_inventory": {
    "total": 5000.0,
    "reason": "Inventory purchased but not yet sold",
    "items": [
      {
        "name": "Raw Materials A",
        "amount": 5000.0,
        "type": "raw"
      }
    ]
  },
  "non_cash_expenses": {
    "depreciation": 4000.0,
    "others": 1000.0,
    "total": 5000.0,
    "reason": "Non-cash expenses added back"
  },
  "supplier_obligations": {
    "total": 8000.0,
    "reason": "Suppliers not yet paid",
    "items": [
      {
        "name": "Supplier Co.",
        "amount": 8000.0
      }
    ]
  },
  "accrued_salaries": {
    "total": 6000.0,
    "reason": "Salaries accrued but unpaid",
    "items": [
      {
        "name": "Staff Salaries",
        "amount": 6000.0
      }
    ]
  },
  "expected_cash": 95000.0,
  "final_result_info": [
    {
      "description": "The difference between monthly profits and current cash is explained by the items above."
    }
  ]
}
```

---

## 6. Break-Even Analysis

#### GET `/brand-owner/financial/break-even-analysis`

Returns break-even analysis including chart metrics, fixed costs breakdown, contribution margin, and
the break-even formula result.

- **Query Parameters:** `year` (int, optional), `month` (int, optional), `branch_id` (string,
  required)
- **Response Object:** `BreakEvenAnalysisModel`

##### Response Shape

```json
{
  "year": 2026,
  "month": 6,
  "month_name": "June",
  "previous_month_name": "May",
  "branch_id": "branch_1",
  "branch_name": "Riyadh Branch",
  "status": "very_safe",
  "current_point_position": 75.0,
  "metrics": {
    "current_point_x": 500000.0,
    "current_point_y": 150000.0,
    "fixed_cost_x": 300000.0,
    "fixed_cost_y": 0.0
  },
  "fixed_costs": {
    "rent_and_utilities": 50000.0,
    "salaries": 80000.0,
    "insurance_and_licenses": 10000.0,
    "total_fixed": 140000.0
  },
  "contribution_margin": {
    "gross_profit_margin": 60.0,
    "variable_costs": 200000.0
  },
  "formula": {
    "fixed_costs": 140000.0,
    "contribution_margin": 0.6,
    "result": 233333.33
  }
}
```

##### Break-Even Status Values

| Status          | Description                          | Strategy Class                                         |
|-----------------|--------------------------------------|--------------------------------------------------------|
| `very_safe`     | Well above break-even point          | `BrandOwnerBreakEvenAnalysisVerySafeStatusStrategy`    |
| `safe`          | Above break-even point               | `BrandOwnerBreakEvenAnalysisSafeStatusStrategy`        |
| `at_break_even` | Exactly at break-even point          | `BrandOwnerBreakEvenAnalysisAtBreakEvenStatusStrategy` |
| `risk`          | Below break-even point               | `BrandOwnerBreakEvenAnalysisRiskStatusStrategy`        |
| `high_risk`     | Significantly below break-even point | `BrandOwnerBreakEvenAnalysisHighRiskStatusStrategy`    |

---

## 7. Operational Profitability

#### GET `/brand-owner/financial/operational-profitability`

Returns operational performance metrics, core indicators, efficiency ratios, and industry
benchmarks.

- **Response Object:** `OperationalProfitabilityModel`

##### Response Shape

```json
{
  "metrics": {
    "overall_performance": "Good",
    "overall_score": "B+",
    "stars_count": 4
  },
  "core_indicators": {
    "operating_profit": 150000.0,
    "operating_profit_change": 12.5,
    "operating_profit_is_positive": true,
    "ebitda": 180000.0,
    "ebitda_change": 10.0,
    "ebitda_is_positive": true,
    "gross_profit_margin": 60.0,
    "gross_profit_margin_change": 2.5,
    "gross_profit_margin_is_positive": true,
    "return_on_sales": 25.0,
    "return_on_sales_label": "Good"
  },
  "efficiency": {
    "productivity_per_employee": 120000.0,
    "productivity_per_employee_change": 5.0,
    "productivity_is_positive": true,
    "labor_cost": 80000.0,
    "labor_cost_label": "16% of revenue",
    "inventory_turnover": 8.5,
    "inventory_turnover_unit": "times/year",
    "average_transaction_value": 125.0,
    "space_efficiency": 4500.0,
    "space_efficiency_unit": "SAR/m²"
  },
  "benchmark": {
    "profit_margin_your_restaurant": 25.0,
    "profit_margin_industry": 20.0,
    "labor_cost_your_restaurant": 16.0,
    "labor_cost_industry": 22.0,
    "inventory_turnover_your_restaurant": 8.5,
    "inventory_turnover_industry": 6.0
  }
}
```

---

## 8. Menu Engineering

#### GET `/brand-owner/financial/menu-engineering`

Returns the menu engineering Boston Matrix with items classified into Stars, Puzzles, Dogs, and
Workhorses categories.

- **Query Parameters:** `year` (int, optional), `month` (int, optional), `compared_year` (int,
  optional), `compared_month` (int, optional), `branch_id` (string, optional)
- **Response Object:** `MenuEngineeringModel`

##### Response Shape

```json
{
  "year": 2026,
  "month": 6,
  "month_name": "June",
  "compared_year": 2026,
  "compared_month": 5,
  "compared_month_name": "May",
  "branch_id": "branch_1",
  "branch_name": "Riyadh Branch",
  "puzzles": {
    "items_count": 8,
    "percentage": 20.0,
    "is_high_profitability": false
  },
  "stars": {
    "items_count": 12,
    "percentage": 30.0,
    "is_high_profitability": true
  },
  "dogs": {
    "items_count": 6,
    "percentage": 15.0,
    "is_high_profitability": false
  },
  "workhorses": {
    "items_count": 14,
    "percentage": 35.0,
    "is_high_profitability": true
  },
  "puzzles_category": {
    "items_count": 8,
    "percentage": 20.0,
    "amount": 80000.0,
    "amount_percentage": 16.0,
    "items": [
      {
        "category": "Burgers",
        "sales_percentage": 8.0,
        "profit_percentage": 5.0
      }
    ]
  },
  "stars_category": {
    "items_count": 12,
    "percentage": 30.0,
    "amount": 180000.0,
    "amount_percentage": 36.0,
    "items": [
      {
        "category": "Grills",
        "sales_percentage": 18.0,
        "profit_percentage": 25.0
      }
    ]
  },
  "dogs_category": {
    "items_count": 6,
    "percentage": 15.0,
    "amount": 40000.0,
    "amount_percentage": 8.0,
    "items": [
      {
        "category": "Salads",
        "sales_percentage": 5.0,
        "profit_percentage": 2.0
      }
    ]
  },
  "workhorses_category": {
    "items_count": 14,
    "percentage": 35.0,
    "amount": 200000.0,
    "amount_percentage": 40.0,
    "items": [
      {
        "category": "Beverages",
        "sales_percentage": 20.0,
        "profit_percentage": 18.0
      }
    ]
  }
}
```

---

## 9. Item Test

#### POST `/brand-owner/financial/item-test/submit`

Submits an item profitability test and returns the calculated result.

- **Request Body:** `ItemTestSubmitRequest`
- **Response Object:** `ItemTestResultModel`

##### Request Body

| Field                  | Type    | Required | Rules                         |
|------------------------|---------|----------|-------------------------------|
| branch_id              | string  | required | Branch identifier             |
| item_name              | string  | required | Name of the item to test      |
| expected_selling_price | number  | required | Proposed selling price        |
| production_cost        | number  | required | Cost to produce the item      |
| expected_sales         | integer | required | Expected monthly sales volume |
| expected_growth        | number  | required | Expected growth percentage    |

##### Request Shape

```json
{
  "branch_id": "branch_1",
  "item_name": "New Chicken Burger",
  "expected_selling_price": 45.0,
  "production_cost": 25.0,
  "expected_sales": 500,
  "expected_growth": 10.0
}
```

##### Response Shape

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

#### GET `/brand-owner/financial/item-test/saved-tests`

Returns saved item tests.

- **Response Object:** `List<ItemTestSavedTestModel>`

---

## 10. Price Simulator

#### GET `/brand-owner/financial/price-simulator/items`

Returns available items for price simulation.

- **Response Object:** `List<PriceSimulatorItemModel>`

##### Response Shape

```json
[
  {
    "id": "item_1",
    "name": "Chicken Burger"
  },
  {
    "id": "item_2",
    "name": "Beef Burger"
  }
]
```

#### GET `/brand-owner/financial/price-simulator/item-info`

Returns pricing and cost details for a specific item.

- **Query Parameters:** `branch_id` (string, required), `item_id` (string, required)
- **Response Object:** `PriceSimulatorItemInfoModel`

##### Response Shape

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

#### POST `/brand-owner/financial/price-simulator/simulate`

Runs a price change simulation and returns the financial impact.

- **Request Body:** `PriceSimulatorSubmitRequest`
- **Response Object:** `PriceSimulatorSimulationResultModel`

##### Request Body

| Field                   | Type   | Required | Rules                            |
|-------------------------|--------|----------|----------------------------------|
| branch_id               | string | required | Branch identifier                |
| item_id                 | string | required | Item identifier                  |
| change_price            | number | required | New proposed price               |
| expected_growth_decline | number | required | Expected sales impact percentage |

##### Response Shape

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
  "expected_growth_percentage": 5.0
}
```

#### GET `/brand-owner/financial/price-simulator/saved-scenarios`

Returns saved simulation scenarios.

- **Response Object:** `List<PriceSimulatorSavedScenarioModel>`

---

## 11. Export Profit & Loss Statement

#### POST `/brand-owner/financial/profit-and-loss/export`

Exports the profit and loss statement in PDF or Excel format.

- **Request Object:** `ExportProfitAndLossRequest`

##### Request Body

| Field       | Type   | Required | Rules                  |
|-------------|--------|----------|------------------------|
| year        | number | required | Report year            |
| month       | number | required | Report month (1-12)    |
| branch_id   | string | required | Branch filter          |
| format_type | string | required | One of: `PDF`, `Excel` |

##### Response Shape

```json
{
  "file_url": "https://example.com/profit_and_loss.pdf"
}
```

---

## 12. Export Sales Channel Analysis

#### POST `/brand-owner/financial/sales-channel-analysis/export`

Exports the sales channel analysis in PDF or Excel format.

- **Request Object:** `ExportSalesChannelAnalysisRequest`

##### Request Body

| Field          | Type   | Required | Rules                   |
|----------------|--------|----------|-------------------------|
| year           | number | required | Report year             |
| month          | number | required | Report month (1-12)     |
| compared_year  | number | required | Comparison year         |
| compared_month | number | required | Comparison month (1-12) |
| branch_id      | string | required | Branch identifier       |
| format_type    | string | required | One of: `PDF`, `Excel`  |

##### Response Shape

```json
{
  "file_url": "https://example.com/sales_channel_analysis.pdf"
}
```

---

## 13. Export Sales Channel Level 2

#### POST `/brand-owner/financial/sales-channel-level2/export`

Exports the sales channel level-2 data in PDF or Excel format.

- **Request Object:** `ExportSalesChannelLevel2Request`

##### Request Body

| Field          | Type   | Required | Rules                   |
|----------------|--------|----------|-------------------------|
| year           | number | required | Report year             |
| month          | number | required | Report month (1-12)     |
| compared_year  | number | required | Comparison year         |
| compared_month | number | required | Comparison month (1-12) |
| branch_id      | string | required | Branch filter           |
| format_type    | string | required | One of: `PDF`, `Excel`  |

##### Response Shape

```json
{
  "file_url": "https://example.com/sales_channel_level2.pdf"
}
```

---

## 14. Export Smart Comparison

#### POST `/brand-owner/financial/smart-comparison/export`

Exports the smart comparison report in PDF or Excel format.

- **Request Object:** `ExportSmartComparisonRequest`

##### Request Body

| Field              | Type   | Required | Rules                        |
|--------------------|--------|----------|------------------------------|
| year               | number | required | Main report year             |
| month              | number | required | Main report month (1-12)     |
| compared_year      | number | required | Comparison year              |
| compared_month     | number | required | Comparison month (1-12)      |
| type               | string | required | `month` or `branch`          |
| branch_id          | string | required | Main branch identifier       |
| compared_branch_id | string | required | Comparison branch identifier |
| format_type        | string | required | One of: `PDF`, `Excel`       |

##### Response Shape

```json
{
  "file_url": "https://example.com/smart_comparison.pdf"
}
```

---

## 15. Export Profit vs Cash Reconciliation

#### POST `/brand-owner/financial/profit-vs-cash-reconciliation/export`

Exports the profit vs cash reconciliation report in PDF or Excel format.

- **Request Object:** `ExportProfitVsCashReconciliationRequest`

##### Request Body

| Field       | Type   | Required | Rules                  |
|-------------|--------|----------|------------------------|
| format_type | string | required | One of: `PDF`, `Excel` |

##### Response Shape

```json
{
  "file_url": "https://example.com/profit_vs_cash_reconciliation.pdf"
}
```

---

## 16. Export Break-Even Analysis

#### POST `/brand-owner/financial/break-even-analysis/export`

Exports the break-even analysis report in PDF or Excel format.

- **Request Object:** `ExportBreakEvenAnalysisRequest`

##### Request Body

| Field       | Type   | Required | Rules                  |
|-------------|--------|----------|------------------------|
| year        | number | required | Report year            |
| month       | number | required | Report month (1-12)    |
| branch_id   | string | required | Branch identifier      |
| format_type | string | required | One of: `PDF`, `Excel` |

##### Response Shape

```json
{
  "file_url": "https://example.com/break_even_analysis.pdf"
}
```

---

## 17. Export Operational Profitability

#### POST `/brand-owner/financial/operational-profitability/export`

Exports the operational profitability report in PDF or Excel format.

- **Request Object:** `ExportOperationalProfitabilityRequest`

##### Request Body

| Field       | Type   | Required | Rules                  |
|-------------|--------|----------|------------------------|
| format_type | string | required | One of: `PDF`, `Excel` |

##### Response Shape

```json
{
  "file_url": "https://example.com/operational_profitability.pdf"
}
```

---

## 18. Export Menu Engineering

#### POST `/brand-owner/financial/menu-engineering/export`

Exports the menu engineering report in PDF or Excel format.

- **Request Object:** `ExportMenuEngineeringRequest`

##### Request Body

| Field          | Type   | Required | Rules                   |
|----------------|--------|----------|-------------------------|
| year           | number | required | Report year             |
| month          | number | required | Report month (1-12)     |
| compared_year  | number | required | Comparison year         |
| compared_month | number | required | Comparison month (1-12) |
| branch_id      | string | required | Branch identifier       |
| format_type    | string | required | One of: `PDF`, `Excel`  |

##### Response Shape

```json
{
  "file_url": "https://example.com/menu_engineering.pdf"
}
```

---

## 19. Export Item Test

#### POST `/brand-owner/financial/item-test/export`

Exports an item test result in PDF or Excel format.

- **Request Object:** `ExportItemTestRequest`

##### Request Body

| Field       | Type   | Required | Rules                  |
|-------------|--------|----------|------------------------|
| test_id     | string | required | Test result identifier |
| format_type | string | required | One of: `PDF`, `Excel` |

##### Response Shape

```json
{
  "file_url": "https://example.com/item_test.pdf"
}
```

---

## 20. Export Price Simulator Scenario

#### POST `/brand-owner/financial/price-simulator/export`

Exports a price simulation scenario in PDF or Excel format.

- **Request Object:** `ExportPriceSimulatorScenarioRequest`

##### Request Body

| Field       | Type   | Required | Rules                  |
|-------------|--------|----------|------------------------|
| scenario_id | string | required | Scenario identifier    |
| format_type | string | required | One of: `PDF`, `Excel` |

##### Response Shape

```json
{
  "file_url": "https://example.com/price_simulator.pdf"
}
```

---

## 21. Email Profit & Loss Statement

#### POST `/brand-owner/financial/profit-and-loss/email`

Emails the profit and loss statement to the specified address.

- **Request Object:** `EmailProfitAndLossRequest`

##### Request Body

| Field     | Type   | Required | Rules               |
|-----------|--------|----------|---------------------|
| year      | number | required | Report year         |
| month     | number | required | Report month (1-12) |
| branch_id | string | required | Branch filter       |
| email     | string | required | Valid email address |

---

## 22. Email Sales Channel Analysis

#### POST `/brand-owner/financial/sales-channel-analysis/email`

Emails the sales channel analysis to the specified address.

- **Request Object:** `EmailSalesChannelAnalysisRequest`

##### Request Body

| Field          | Type   | Required | Rules                   |
|----------------|--------|----------|-------------------------|
| year           | number | required | Report year             |
| month          | number | required | Report month (1-12)     |
| compared_year  | number | required | Comparison year         |
| compared_month | number | required | Comparison month (1-12) |
| branch_id      | string | required | Branch identifier       |
| email          | string | required | Valid email address     |

---

## 23. Email Smart Comparison

#### POST `/brand-owner/financial/smart-comparison/email`

Emails the smart comparison report to the specified address.

- **Request Object:** `EmailSmartComparisonRequest`

##### Request Body

| Field              | Type   | Required | Rules                        |
|--------------------|--------|----------|------------------------------|
| year               | number | required | Main report year             |
| month              | number | required | Main report month (1-12)     |
| compared_year      | number | required | Comparison year              |
| compared_month     | number | required | Comparison month (1-12)      |
| type               | string | required | `month` or `branch`          |
| branch_id          | string | required | Main branch identifier       |
| compared_branch_id | string | required | Comparison branch identifier |
| email              | string | required | Valid email address          |

---

## 24. Email Profit vs Cash Reconciliation

#### POST `/brand-owner/financial/profit-vs-cash-reconciliation/email`

Emails the profit vs cash reconciliation report to the specified address.

- **Request Object:** `EmailProfitVsCashReconciliationRequest`

##### Request Body

| Field | Type   | Required | Rules               |
|-------|--------|----------|---------------------|
| email | string | required | Valid email address |

---

## 25. Email Operational Profitability

#### POST `/brand-owner/financial/operational-profitability/email`

Emails the operational profitability report to the specified address.

- **Request Object:** `EmailOperationalProfitabilityRequest`

##### Request Body

| Field | Type   | Required | Rules               |
|-------|--------|----------|---------------------|
| email | string | required | Valid email address |

---

## 26. Email Menu Engineering

#### POST `/brand-owner/financial/menu-engineering/email`

Emails the menu engineering report to the specified address.

- **Request Object:** `EmailMenuEngineeringRequest`

##### Request Body

| Field          | Type   | Required | Rules                   |
|----------------|--------|----------|-------------------------|
| year           | number | required | Report year             |
| month          | number | required | Report month (1-12)     |
| compared_year  | number | required | Comparison year         |
| compared_month | number | required | Comparison month (1-12) |
| branch_id      | string | required | Branch identifier       |
| email          | string | required | Valid email address     |

---

## 27. Email Item Test

#### POST `/brand-owner/financial/item-test/email`

Emails an item test result to the specified address.

- **Request Object:** `EmailItemTestRequest`

##### Request Body

| Field   | Type   | Required | Rules                  |
|---------|--------|----------|------------------------|
| test_id | string | required | Test result identifier |
| email   | string | required | Valid email address    |

---

## 28. Email Price Simulator Scenario

#### POST `/brand-owner/financial/price-simulator/email`

Emails a price simulation scenario to the specified address.

- **Request Object:** `EmailPriceSimulatorScenarioRequest`

##### Request Body

| Field       | Type   | Required | Rules               |
|-------------|--------|----------|---------------------|
| scenario_id | string | required | Scenario identifier |
| email       | string | required | Valid email address |

---

## 29. Get Branches

#### GET `/brand-owner/branches`

Returns the list of branches for the brand owner.

- **Response Object:** `BrandOwnerBranchesResponseModel`

##### Response Shape

```json
{
  "branches": [
    {
      "id": "branch_1",
      "name": "Riyadh Branch"
    },
    {
      "id": "branch_2",
      "name": "Jeddah Branch"
    }
  ]
}
```

---

## Models Reference

### ProfitAndLossStatementModel

| Field   | Type                        | Description         |
|---------|-----------------------------|---------------------|
| year    | `int`                       | Report year         |
| month   | `int`                       | Report month        |
| summary | `ProfitAndLossSummaryModel` | P&L summary data    |
| chart   | `ProfitAndLossChartModel`   | P&L chart breakdown |

### ProfitAndLossSummaryModel

| Field                    | Type     | Description           |
|--------------------------|----------|-----------------------|
| total_revenue            | `double` | Total revenue         |
| total_expenses           | `double` | Total expenses        |
| net_profit               | `double` | Net profit            |
| profit_margin_percentage | `double` | Profit margin %       |
| is_profit                | `bool`   | Whether net is profit |

### ProfitAndLossChartModel

| Field                 | Type     | Description           |
|-----------------------|----------|-----------------------|
| turnover_total        | `double` | Total turnover        |
| direct_cost_total     | `double` | Direct costs          |
| gross_profit          | `double` | Gross profit          |
| grand_total_cost      | `double` | Grand total costs     |
| profitability_amount  | `double` | Profitability amount  |
| g_and_a_expenses      | `double` | G&A expenses          |
| other_income_expenses | `double` | Other income/expenses |
| net_profit_and_loss   | `double` | Net profit and loss   |

### SalesChannelAnalysisModel

| Field          | Type                                 | Description              |
|----------------|--------------------------------------|--------------------------|
| year           | `int`                                | Main report year         |
| month          | `int`                                | Main report month        |
| compared_year  | `int`                                | Comparison year          |
| compared_month | `int`                                | Comparison month         |
| month_name     | `String`                             | Main month display name  |
| branch_id      | `String`                             | Branch identifier        |
| branch_name    | `String`                             | Branch display name      |
| summary        | `SalesChannelAnalysisSummaryModel`   | Channel analysis summary |
| chart_data     | `SalesChannelAnalysisChartDataModel` | Comparison chart data    |

### SalesChannelAnalysisSummaryModel

| Field                          | Type     | Description         |
|--------------------------------|----------|---------------------|
| total_sales                    | `double` | Total sales amount  |
| total_profitability            | `double` | Total profitability |
| total_profitability_percentage | `double` | Profitability %     |
| net_profit_percentage          | `double` | Net profit %        |
| profit_margin_percentage       | `double` | Profit margin %     |

### SalesChannelLevel2Model

| Field          | Type                                     | Description        |
|----------------|------------------------------------------|--------------------|
| year           | `int`                                    | Report year        |
| month          | `int`                                    | Report month       |
| compared_year  | `int`                                    | Comparison year    |
| compared_month | `int`                                    | Comparison month   |
| month_name     | `String`                                 | Month display name |
| total_count    | `int`                                    | Total items count  |
| items          | `List<SalesChannelLevel2ItemModel>`      | Channel items      |
| chart_data     | `List<SalesChannelLevel2ChartDataModel>` | Monthly chart data |

### SalesChannelLevel2ItemModel

| Field                    | Type     | Description             |
|--------------------------|----------|-------------------------|
| id                       | `String` | Item identifier         |
| name                     | `String` | Channel/item name       |
| image                    | `String` | Logo/image URL          |
| sales_amount             | `double` | Total sales             |
| percentage_change        | `double` | Change percentage       |
| is_positive              | `bool`   | Positive change flag    |
| comparison_text          | `String` | Display comparison text |
| commission_amount        | `double` | Commission amount       |
| commission_percentage    | `double` | Commission %            |
| profitability_amount     | `double` | Profitability amount    |
| profitability_percentage | `double` | Profitability %         |
| order_count              | `int`    | Number of orders        |
| average_order_value      | `double` | Average order value     |

### SmartComparisonModel

| Field                | Type                             | Description                    |
|----------------------|----------------------------------|--------------------------------|
| year                 | `int`                            | Main report year               |
| month                | `int`                            | Main report month              |
| compared_year        | `int`                            | Comparison year                |
| compared_month       | `int`                            | Comparison month               |
| month_name           | `String`                         | Main month display name        |
| compared_month_name  | `String`                         | Comparison month display name  |
| type                 | `BrandOwnerSmartCompareTypeEnum` | `month` or `branch` comparison |
| branch_id            | `String`                         | Main branch identifier         |
| branch_name          | `String`                         | Main branch name               |
| compared_branch_id   | `String?`                        | Compared branch identifier     |
| compared_branch_name | `String?`                        | Compared branch name           |
| summary              | `SmartComparisonSummaryModel`    | Comparison summary             |
| chart_data           | `SmartComparisonChartDataModel`  | Comparison chart data          |

### ProfitVsCashReconciliationModel

| Field                           | Type                                 | Description                           |
|---------------------------------|--------------------------------------|---------------------------------------|
| date_range_label                | `String`                             | Report period label                   |
| monthly_profits                 | `double`                             | Book profits for the period           |
| current_cash                    | `double`                             | Actual cash on hand                   |
| difference                      | `double`                             | Gap between profits and cash          |
| pending_sales                   | `PendingSalesModel`                  | Uncollected sales                     |
| rent_paid                       | `RentPaidModel`                      | Rent paid vs recognized               |
| previous_expense_reconciliation | `PreviousExpenseReconciliationModel` | Prior period expense adjustments      |
| advances_fixed_assets           | `AdvancesFixedAssetsModel`           | Advances and fixed asset purchases    |
| zakat_taxes                     | `ZakatTaxesModel`                    | Zakat and tax provisions              |
| unused_inventory                | `InventoryModel`                     | Inventory not yet sold                |
| non_cash_expenses               | `NonCashExpenseModel`                | Depreciation and other non-cash items |
| supplier_obligations            | `SupplierObligationsModel`           | Unpaid supplier invoices              |
| accrued_salaries                | `AccruedSalariesModel`               | Accrued but unpaid salaries           |
| expected_cash                   | `double`                             | Expected cash after adjustments       |
| final_result_info               | `List<FinalResultInfoModel>`         | Explanatory notes                     |

### BreakEvenAnalysisModel

| Field                  | Type                                       | Description                     |
|------------------------|--------------------------------------------|---------------------------------|
| year                   | `int`                                      | Report year                     |
| month                  | `int`                                      | Report month                    |
| month_name             | `String`                                   | Month display name              |
| previous_month_name    | `String`                                   | Previous month display name     |
| branch_id              | `String`                                   | Branch identifier               |
| branch_name            | `String`                                   | Branch name                     |
| status                 | `String`                                   | Break-even status key           |
| current_point_position | `double`                                   | Current point position on chart |
| metrics                | `BreakEvenAnalysisMetricsModel`            | Chart metrics (X/Y points)      |
| fixed_costs            | `BreakEvenAnalysisFixedCostsModel`         | Fixed costs breakdown           |
| contribution_margin    | `BreakEvenAnalysisContributionMarginModel` | Contribution margin data        |
| formula                | `BreakEvenAnalysisFormulaModel`            | Break-even formula result       |

### OperationalProfitabilityModel

| Field           | Type                                          | Description                           |
|-----------------|-----------------------------------------------|---------------------------------------|
| metrics         | `OperationalProfitabilityMetricsModel`        | Overall performance metrics           |
| core_indicators | `OperationalProfitabilityCoreIndicatorsModel` | Operating profit, EBITDA, ROS         |
| efficiency      | `OperationalProfitabilityEfficiencyModel`     | Productivity, labor, inventory ratios |
| benchmark       | `OperationalProfitabilityBenchmarkModel`      | Industry comparison benchmarks        |

### MenuEngineeringModel

| Field               | Type                               | Description                         |
|---------------------|------------------------------------|-------------------------------------|
| year                | `int`                              | Report year                         |
| month               | `int`                              | Report month                        |
| month_name          | `String`                           | Month display name                  |
| compared_year       | `int`                              | Comparison year                     |
| compared_month      | `int`                              | Comparison month                    |
| compared_month_name | `String`                           | Comparison month display name       |
| branch_id           | `String`                           | Branch identifier                   |
| branch_name         | `String`                           | Branch name                         |
| puzzles             | `MenuEngineeringOverviewItemModel` | High popularity, low profitability  |
| stars               | `MenuEngineeringOverviewItemModel` | High popularity, high profitability |
| dogs                | `MenuEngineeringOverviewItemModel` | Low popularity, low profitability   |
| workhorses          | `MenuEngineeringOverviewItemModel` | Low popularity, high profitability  |
| puzzles_category    | `MenuEngineeringCategoryModel`     | Puzzle items detail                 |
| stars_category      | `MenuEngineeringCategoryModel`     | Star items detail                   |
| dogs_category       | `MenuEngineeringCategoryModel`     | Dog items detail                    |
| workhorses_category | `MenuEngineeringCategoryModel`     | Workhorse items detail              |

### ItemTestResultModel

| Field                   | Type     | Description                     |
|-------------------------|----------|---------------------------------|
| id                      | `String` | Test result identifier          |
| profit_margin           | `double` | Calculated profit margin %      |
| profit_margin_label     | `String` | Formatted margin label          |
| expected_monthly_profit | `double` | Projected monthly profit        |
| expected_classification | `String` | Item classification (Star, etc) |
| menu_impact             | `String` | Menu impact description         |
| date                    | `String` | Test date                       |
| branch_name             | `String` | Branch name                     |
| item_name               | `String` | Item name                       |
| expected_selling_price  | `double` | Proposed selling price          |
| production_cost         | `double` | Production cost                 |
| expected_sales          | `int`    | Expected monthly sales          |
| expected_growth         | `double` | Expected growth %               |

### PriceSimulatorSimulationResultModel

| Field                             | Type     | Description                       |
|-----------------------------------|----------|-----------------------------------|
| new_price                         | `double` | New proposed price                |
| expected_sales                    | `int`    | Expected sales volume             |
| expected_sales_change_percentage  | `double` | Sales change %                    |
| new_unit_profit                   | `double` | Profit per unit at new price      |
| new_unit_profit_change_percentage | `double` | Unit profit change %              |
| new_monthly_profit                | `double` | Total monthly profit at new price |
| profit_change                     | `double` | Absolute profit change            |
| profit_change_percentage          | `double` | Profit change %                   |
| date                              | `String` | Simulation date                   |
| branch_name                       | `String` | Branch name                       |
| item_name                         | `String` | Item name                         |
| current_selling_price             | `double` | Original selling price            |
| production_cost                   | `double` | Production cost                   |
| current_monthly_sales             | `int`    | Original monthly sales            |
| expected_growth_percentage        | `double` | Expected growth/decline %         |

### BrandOwnerBranchModel

| Field | Type     | Description         |
|-------|----------|---------------------|
| id    | `String` | Branch identifier   |
| name  | `String` | Branch display name |

### Export Request Models

#### ExportProfitAndLossRequest

| Field       | Type      | Required | Rules               |
|-------------|-----------|----------|---------------------|
| year        | `int`     | required | Report year         |
| month       | `int`     | required | Report month (1-12) |
| branch_id   | `String?` | optional | Branch filter       |
| format_type | `String`  | required | `PDF` or `Excel`    |
| email       | `String?` | optional | For email export    |

#### ExportSmartComparisonRequest

| Field              | Type                             | Required | Rules                        |
|--------------------|----------------------------------|----------|------------------------------|
| year               | `int`                            | required | Main report year             |
| month              | `int`                            | required | Main report month            |
| compared_year      | `int`                            | required | Comparison year              |
| compared_month     | `int`                            | required | Comparison month             |
| type               | `BrandOwnerSmartCompareTypeEnum` | required | `month` or `branch`          |
| branch_id          | `String`                         | required | Main branch identifier       |
| compared_branch_id | `String?`                        | optional | Comparison branch identifier |
| format_type        | `String`                         | required | `PDF` or `Excel`             |
| email              | `String?`                        | optional | For email export             |

---

## Enums Reference

### BrandOwnerSmartCompareTypeEnum

| Value    | Description                 |
|----------|-----------------------------|
| `month`  | Month-to-month comparison   |
| `branch` | Branch-to-branch comparison |

### Break-Even Analysis Status Values

| Value           | Description                          |
|-----------------|--------------------------------------|
| `very_safe`     | Well above break-even point          |
| `safe`          | Above break-even point               |
| `at_break_even` | Exactly at break-even point          |
| `risk`          | Below break-even point               |
| `high_risk`     | Significantly below break-even point |

### Export Format Types

| Value   | Description       |
|---------|-------------------|
| `PDF`   | PDF document      |
| `Excel` | Excel spreadsheet |

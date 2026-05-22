# Branch Manager — Price Comparison API

## Scope

These are the endpoint contracts used by `BranchManagerPriceComparisonRepositoryImpl` on the client.
The server does not currently implement these endpoints in this project; the repo uses mocked data for UI development. The following document describes a production-ready, endpoint-first contract in the same style as the branch manager settings docs.

This spec covers:

- Saved price comparisons list
- Price comparison details
- Exporting comparisons
- Creating an order from a recommendation

---

## 1. List Saved Price Comparisons

#### GET `/branch-manager/price-comparisons`

Return a list of saved price-comparison snapshots for the branch manager view.

- Query Parameters:
  - `timeKey` (optional): enum { `last_24_hours`, `last_7_days`, `last_30_days` } — server-side filter by saved date range.
  - `itemId` (optional): string — filter results to a single item.
  - `supplierId` (optional): string — filter results originating from a supplier or source.
  - `page` (optional): integer — page index for pagination.

- Response 200 (application/json): `PriceComparisonListResponse`

```json
{
  "data": [
    {
      "id": "pc_001",
      "itemId": "item_001",
      "itemName": "Fresh Beef (40 Kg)",
      "itemCode": "BEEF-40",
      "savedDate": "2025-06-29",
      "quantity": 60
    }
  ]
}
```

---

## 2. Get Price Comparison Details

#### GET `/branch-manager/price-comparisons/{comparisonId}`

Return full details for a saved comparison, including candidate sources, computed factors, and price trends.

- Path Parameters:
  - `comparisonId` (required): string

- Response 200 (application/json): `PriceComparisonDetailsResponse`

```json
{
  "data": {
    "comparisonId": "pc_001",
    "itemId": "item_001",
    "itemName": "Fresh Beef (40 Kg)",
    "itemCode": "BEEF-40",
    "itemUnit": "Kg",
    "itemLogo": null,
    "itemPrice": 1200.0,
    "quantity": 60,
    "sources": {
      "directSupplier": {
        "price": 1200.0,
        "deliveryDays": "5-7 days",
        "rating": 4.5,
        "sourceId": "sup_001"
      },
      "viaPurchasingOfficer": {
        "price": 1150.0,
        "deliveryDays": "7-10 days",
        "rating": 4.2,
        "sourceId": "po_001"
      },
      "internalTransfer": {
        "price": 1100.0,
        "deliveryDays": "3-5 days",
        "rating": 4.0,
        "sourceId": "branch_002"
      }
    },
    "factors": {
      "bestCompliance": {
        "sourceType": "direct_supplier",
        "sourceId": "sup_001",
        "sourceName": "Al Safa Meat Suppliers",
        "score": 4.5
      },
      "fastestDelivery": {
        "sourceType": "internal_transfer",
        "sourceId": "branch_002",
        "sourceName": "Branch 2 - Downtown",
        "score": 3.5
      },
      "lowestPrice": {
        "sourceType": "internal_transfer",
        "sourceId": "branch_002",
        "sourceName": "Branch 2 - Downtown",
        "score": 1100.0
      }
    },
    "priceTrends": [
      { "date": "2025-04-01", "value": 1050.0 },
      { "date": "2025-05-01", "value": 1150.0 },
      { "date": "2025-06-01", "value": 1200.0 }
    ]
  }
}
```

---

## 3. Export Price Comparison

#### GET `/branch-manager/price-comparisons/{comparisonId}/export`

Export a single comparison to a file. Prefer asynchronous generation for large exports.

- Path Parameters:
  - `comparisonId` (required): string
- Query Parameters:
  - `formatType` (required): enum { `PDF`, `Excel` }

- Response 200 (application/json): `ExportResponse`

```json
{
  "url": "https://api.example.com/exports/price_comparison_pc_001_161234567890.pdf"
}
```

---

## 4. Create Order From Recommended Source

#### POST `/branch-manager/price-comparisons/{comparisonId}/orders`

Create a purchase order using the recommended source from the comparison or an overridden preferred source.

- Path Parameters:
  - `comparisonId` (required): string

---

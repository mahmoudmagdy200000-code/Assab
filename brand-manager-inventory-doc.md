# Brand Manager Inventory API

## Screen

`BrandManagerInventoryManagementScreen`

This screen shows the brand manager inventory work queues and details for:

- Daily inventory requests
- Waste and damage requests
- Request details and timelines
- Approve and reject actions

---

## Daily Inventory Requests

#### GET `/branch-manager/inventory/daily-requests`

Returns a paginated list of daily inventory requests.

- **Query Parameters Object:** `InventoryRequestsQuery`
- **Response Object:** `BrandManagerDailyInventoryRequestsResponse`

##### Query Parameters

| Field  | Type   | Required | Rules                    |
| ------ | ------ | -------- | ------------------------ |
| page   | number | optional | 1-based page index.      |
| limit  | number | optional | Default 20. Max 100.     |
| status | string | optional | `pending` or `completed` |
| branch | string | optional | Filter by branch id.     |

##### Response Shape

```json
{
  "data": {
    "requests": [
      {
        "id": "string",
        "status": "pending",
        "inventory_date": "2026-05-16T08:30:00.000Z",
        "start_time": "08:30 AM",
        "branch": {
          "id": "string",
          "name": "string"
        }
      }
    ],
    "pagination": {
      "page": 1,
      "limit": 20,
      "total": 0
    }
  }
}
```

---

## Waste and Damage Requests

#### GET `/branch-manager/inventory/waste-damage-requests`

Returns a paginated list of waste and damage requests.

- **Query Parameters Object:** `InventoryRequestsQuery`
- **Response Object:** `BrandManagerWastAndDamageRequestsResponse`

##### Response Shape

```json
{
  "data": {
    "requests": [
      {
        "id": "string",
        "report_type": "waste",
        "status": "pending",
        "submission_date": "2026-05-16T10:15:00.000Z",
        "items_count": 4,
        "branch": {
          "id": "string",
          "name": "string"
        }
      }
    ],
    "pagination": {
      "page": 1,
      "limit": 20,
      "total": 0
    }
  }
}
```

---

## Daily Inventory Details

#### GET `/branch-manager/inventory/daily-requests/{requestId}`

Returns full details for a daily inventory request.

- **Path Parameters Object:** `RequestIdPath`
- **Response Object:** `BrandManagerDailyInventoryDetailsResponse`

##### Path Parameters

| Field     | Type   | Required | Rules             |
| --------- | ------ | -------- | ----------------- |
| requestId | string | required | Valid request id. |

##### Response Shape

```json
{
  "data": {
    "id": "string",
    "branch_name": "string",
    "branch_open_hours": "Mon - Fri / 9Am - 8Pm",
    "inventory_date": "2026-05-16T08:30:00.000Z",
    "branch_image_url": "string",
    "reporter_name": "string",
    "reporter_image_url": "string",
    "status": "pending",
    "summary": {
      "products_inventoried": "12/12 (100%)",
      "products_with_discrepancies": "2",
      "matching_products": "10",
      "total_discrepancy_value": "-45",
      "discrepancy_type": "4 products"
    },
    "items": [
      {
        "product_name": "string",
        "expected_balance": "string",
        "actual_balance": "string",
        "difference": "string",
        "justification_note": "string",
        "explanatory_photo_url": "string",
        "explanatory_photo_name": "string",
        "image_url": "string"
      }
    ],
    "timelines": [
      {
        "id": "string",
        "event_type": "string",
        "name": "string",
        "image": "string",
        "occurred_at": "2026-05-16T10:15:00.000Z"
      }
    ]
  }
}
```

---

## Waste and Damage Details

#### GET `/branch-manager/inventory/waste-damage-requests/{requestId}`

Returns full details for a waste or damage request.

- **Path Parameters Object:** `RequestIdPath`
- **Response Object:** `BrandManagerWastAndDamageDetailsResponse`

##### Response Shape

```json
{
  "data": {
    "id": "string",
    "report_type": "waste",
    "status": "pending",
    "branch_name": "string",
    "branch_open_hours": "Mon - Fri / 9Am - 8Pm",
    "submission_date": "2026-05-16T10:15:00.000Z",
    "branch_image_url": "string",
    "reporter_name": "string",
    "reporter_image_url": "string",
    "summary": {
      "recorded_by": "string",
      "assigned_by": "string",
      "recording_time": "10:32 AM",
      "number_of_items": "4 products",
      "matching_products": "10",
      "total_value": "25846",
      "warning": "Mixed waste and damage"
    },
    "items": [
      {
        "product_name": "string",
        "problem_type": "Waste",
        "quantity": "20",
        "reason": "Expired",
        "total_value": "25846",
        "justification_note": "string",
        "explanatory_photo_url": "string",
        "image_url": "string"
      }
    ],
    "timelines": [
      {
        "id": "string",
        "event_type": "string",
        "name": "string",
        "image": "string",
        "occurred_at": "2026-05-16T10:15:00.000Z"
      }
    ]
  }
}
```

---

## Approve and Reject

#### POST `/branch-manager/inventory/daily-requests/{requestId}/approve`

Approves a daily inventory request.

- **Path Parameters Object:** `RequestIdPath`
- **Request Body:** none required for the current UI flow
- **Response Object:** `BrandManagerInventoryActionResponse`

#### POST `/branch-manager/inventory/daily-requests/{requestId}/reject`

Rejects a daily inventory request.

- **Path Parameters Object:** `RequestIdPath`
- **Request Body:** none required for the current UI flow
- **Response Object:** `BrandManagerInventoryActionResponse`

#### POST `/branch-manager/inventory/waste-damage-requests/{requestId}/approve`

Approves a waste or damage request.

- **Path Parameters Object:** `RequestIdPath`
- **Request Body:** none required for the current UI flow
- **Response Object:** `BrandManagerInventoryActionResponse`

#### POST `/branch-manager/inventory/waste-damage-requests/{requestId}/reject`

Rejects a waste or damage request.

- **Path Parameters Object:** `RequestIdPath`
- **Request Body:** none required for the current UI flow
- **Response Object:** `BrandManagerInventoryActionResponse`

##### Response Shape

```json
{
  "data": {
    "request_id": "string",
    "status": "approved",
    "processed_at": "2026-05-16T10:15:00.000Z"
  }
}
```

---

## Implementation Notes

- The details screens map directly to the corresponding details endpoints.
- Approve and reject actions are triggered from the general actions bottom sheet.
- Timelines should be rendered if provided; omit the section when empty.
- If the backend uses different date formats, keep the key names and adjust parsing in the client only.

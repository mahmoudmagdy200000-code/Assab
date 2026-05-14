# Fixed Assets — Example Response Bodies

All endpoints below assume:
- Base: `/api/v1/branch-manager/fixed-assets`
- Header: `Authorization: Bearer <sanctum-token>`
- Auth user: active Branch Manager assigned to a branch

All responses wrap doc shapes inside the BaseController envelope: `{success, message, data, ...}`.

---

## 1. GET `/overview`

```json
{
  "success": true,
  "message": "Overview retrieved successfully",
  "data": {
    "total": 60,
    "excellent": 22,
    "maintenance": 18,
    "problem": 20
  }
}
```

---

## 2. GET `/zones`

```json
{
  "success": true,
  "message": "Zones retrieved successfully",
  "data": {
    "data": [
      { "id": "9e15c8ba-...-0001", "name": "Front of House" },
      { "id": "9e15c8ba-...-0002", "name": "Kitchen" },
      { "id": "9e15c8ba-...-0003", "name": "Office" },
      { "id": "9e15c8ba-...-0004", "name": "Outdoor" },
      { "id": "9e15c8ba-...-0005", "name": "Storage" }
    ]
  }
}
```

---

## 3. GET `/types`

```json
{
  "success": true,
  "message": "Asset types retrieved successfully",
  "data": {
    "data": [
      { "id": "9e15c8ba-...-1001", "name": "Air Conditioners" },
      { "id": "9e15c8ba-...-1002", "name": "Coffee Machines" },
      { "id": "9e15c8ba-...-1003", "name": "Cooking Equipment" },
      { "id": "9e15c8ba-...-1004", "name": "Furniture" },
      { "id": "9e15c8ba-...-1005", "name": "Lighting" },
      { "id": "9e15c8ba-...-1006", "name": "POS Devices" },
      { "id": "9e15c8ba-...-1007", "name": "Refrigerators" },
      { "id": "9e15c8ba-...-1008", "name": "Storage Shelves" }
    ]
  }
}
```

---

## 4. GET `/employees`

```json
{
  "success": true,
  "message": "Employees retrieved successfully",
  "data": {
    "data": [
      { "id": "9e15c8ba-...-2001", "name": "Ahmed Al-Saud", "image": "https://example.com/storage/managers/ahmed.jpg" },
      { "id": "9e15c8ba-...-2002", "name": "Faisal Khan", "image": "" },
      { "id": "9e15c8ba-...-3001", "name": "Yusuf (Cashier)", "image": "" }
    ]
  }
}
```

---

## 5. GET `/branches`

```json
{
  "success": true,
  "message": "Branches retrieved successfully",
  "data": {
    "data": [
      { "id": "9e15c8ba-...-4001", "name": "Riyadh - Olaya", "image": "" },
      { "id": "9e15c8ba-...-4002", "name": "Jeddah - Tahlia", "image": "" }
    ]
  }
}
```

---

## 6. GET `/assets?search=coffee`

```json
{
  "success": true,
  "message": "Assets retrieved successfully",
  "data": {
    "data": [
      {
        "id": "9e15c8ba-...-5001",
        "name": "Coffee Machines #1",
        "code": "FA-K3Z2M8VL",
        "image": "",
        "location": { "id": "9e15c8ba-...-0002", "name": "Kitchen" },
        "status": "excellent",
        "assigned_to": "Ahmed Al-Saud",
        "age": "6 months",
        "value": "12500",
        "custody": "2 months"
      }
    ]
  }
}
```

---

## 7. GET `/assets/{assetId}`

```json
{
  "success": true,
  "message": "Asset details retrieved successfully",
  "data": {
    "data": {
      "id": "9e15c8ba-...-5001",
      "name": "Coffee Machines #1",
      "code": "FA-K3Z2M8VL",
      "image": "https://example.com/storage/fixed-assets/assets/k3z2.jpg",
      "location": { "id": "9e15c8ba-...-0002", "name": "Kitchen" },
      "status": "excellent",
      "assigned_to": "Ahmed Al-Saud",
      "age": "6 months",
      "value": "12500",
      "custody": "2 months"
    }
  }
}
```

---

## 8. PATCH `/assets/{assetId}/settings`

Request: multipart — `new_zone_id=<uuid>`, optional `new_image=<file>`.

```json
{
  "success": true,
  "message": "Asset settings updated successfully",
  "data": {
    "id": "9e15c8ba-...-5001",
    "name": "Coffee Machines #1",
    "code": "FA-K3Z2M8VL",
    "image": "https://example.com/storage/fixed-assets/assets/new.jpg",
    "location": { "id": "9e15c8ba-...-0003", "name": "Office" },
    "status": "excellent",
    "assigned_to": "Ahmed Al-Saud",
    "age": "6 months",
    "value": "12500",
    "custody": "2 months"
  }
}
```

---

## 9. POST `/assets/search`

Request (general):
```json
{
  "search_type": "general",
  "zone_id": "9e15c8ba-...-0002",
  "type_id": "9e15c8ba-...-1002",
  "employee_id": "9e15c8ba-...-2001",
  "date": "2026-05-12"
}
```

Request (quick):
```json
{
  "search_type": "quick",
  "quick_search_key": "under_maintenance"
}
```

Response:
```json
{
  "success": true,
  "message": "Search results retrieved successfully",
  "data": {
    "asset_type_name": "Coffee Machines",
    "data": {
      "summary": {
        "total_items": 60,
        "total_items_matching_search": 5,
        "total_items_matching_search_excellent_status": 2,
        "total_items_matching_search_maintenance_status": 2,
        "total_items_matching_search_problem_status": 1
      },
      "items": [
        {
          "id": "9e15c8ba-...-5001",
          "name": "Coffee Machines #1",
          "code": "FA-K3Z2M8VL",
          "image": "",
          "location": { "id": "9e15c8ba-...-0002", "name": "Kitchen" },
          "status": "excellent",
          "assigned_to": "Ahmed Al-Saud",
          "age": "6 months",
          "value": "12500",
          "custody": "2 months"
        }
      ]
    }
  }
}
```

---

## 10. POST `/assets/search-image`

Same response shape as `/assets/search`. Currently returns empty `items` (UI button not wired yet).

---

## 11. GET `/receive-assets`

Note: camelCase keys per spec.

```json
{
  "success": true,
  "message": "Pending assets retrieved successfully",
  "data": {
    "data": [
      {
        "id": "9e15c8ba-...-6001",
        "assetName": "New Coffee Machine",
        "assetCode": "PR-COF-001",
        "assetImage": ""
      },
      {
        "id": "9e15c8ba-...-6002",
        "assetName": "Walk-in Refrigerator",
        "assetCode": "PR-REF-002",
        "assetImage": ""
      }
    ]
  }
}
```

---

## 12. POST `/receive-assets/confirm`

Request: multipart. Top-level `type=from_branch|from_finance`. Per-item keyed by `assetId` (the pending receipt id):
```
type=from_branch
items[9e15c8ba-...-6001][assetId]=9e15c8ba-...-6001
items[9e15c8ba-...-6001][assignedZoneId]=9e15c8ba-...-0002
items[9e15c8ba-...-6001][assetTypeId]=9e15c8ba-...-1002
items[9e15c8ba-...-6001][assetCount]=3
items[9e15c8ba-...-6001][excellentCount]=2
items[9e15c8ba-...-6001][needAttentionCount]=1
items[9e15c8ba-...-6001][problemCount]=0
items[9e15c8ba-...-6001][image]=<file>
```

Response:
```json
{
  "success": true,
  "message": "Assets received successfully",
  "data": {
    "session_id": "9e15c8ba-...-7001",
    "received_count": 1
  }
}
```

---

## 13. POST `/requests/transfer-disposal`

Request (transfer_to_branch, multipart):
```
type=transfer_to_branch
branchId=9e15c8ba-...-4002
autoApprove=false
assets[0][assetId]=9e15c8ba-...-5001
assets[0][transferReason]=Need it for new branch opening
assets[0][documentationPhotos]=<file>
```

Request (disposal):
```
type=disposal
disposalDate=2026-05-30
disposalTime=14:00
disposalMethod=scrap
assets[0][assetId]=9e15c8ba-...-5002
assets[0][disposalReason]=End of useful life
assets[0][conditionDescription]=Heavy corrosion, intermittent power
assets[0][visualEvidence]=<file>
```

Request (external_transfer):
```
type=external_transfer
assets[0][assetId]=9e15c8ba-...-5003
```

Response (all three types):
```json
{
  "success": true,
  "message": "Request submitted successfully",
  "data": {
    "id": "9e15c8ba-...-8001",
    "kind": "transfer_to_branch",
    "status": "pending"
  }
}
```

---

## 14. GET `/requests/modifications?page=1`

```json
{
  "success": true,
  "message": "Modification requests retrieved successfully",
  "data": {
    "data": [
      { "id": "9e15c8ba-...-9001", "asset_name": "Coffee Machines #1", "status": "approved" },
      { "id": "9e15c8ba-...-9002", "asset_name": "Refrigerators #2", "status": "pending" },
      { "id": "9e15c8ba-...-9003", "asset_name": "POS Devices #3", "status": "approved" },
      { "id": "9e15c8ba-...-9004", "asset_name": "Furniture #4", "status": "pending" }
    ],
    "meta": {
      "current_page": 1,
      "last_page": 1,
      "total": 4
    }
  }
}
```

---

## 15. POST `/requests/modifications`

Request (multipart):
```
asset_id=9e15c8ba-...-5001
new_status=need_attention
reason=Detected wear during routine check
attachment=<file>
done_actions[]=initial_check
done_actions[]=cleaning
next_action=need_technician
approval_request_owner_note=Please review at earliest convenience
```

Response:
```json
{
  "success": true,
  "message": "Modification request submitted",
  "data": {
    "id": "9e15c8ba-...-9005",
    "status": "pending"
  }
}
```

---

## 16. GET `/requests/modifications/{requestId}`

```json
{
  "success": true,
  "message": "Modification details retrieved successfully",
  "data": {
    "status": "pending",
    "asset_details": {
      "id": "9e15c8ba-...-5001",
      "name": "Coffee Machines #1",
      "code": "FA-K3Z2M8VL",
      "image": "",
      "location": { "id": "9e15c8ba-...-0002", "name": "Kitchen" },
      "status": "excellent",
      "assigned_to": "Ahmed Al-Saud",
      "age": "6 months",
      "value": "12500",
      "custody": "2 months"
    },
    "modification_details": {
      "new_status": "need_attention",
      "reason": "Detected wear during routine check; requires technician review.",
      "attachment": {
        "id": "9e15c8ba-...-aa01",
        "file_name": "modification_doc_xyz.jpg",
        "file_type": "image/jpeg",
        "file_size": 348112,
        "url": "https://example.com/storage/fixed-assets/modification_doc/sample-xyz.jpg",
        "uploaded_at": "2026-05-10T12:00:00+00:00"
      }
    },
    "required_actions": {
      "done_actions": ["initial_check", "cleaning"],
      "next_action": "need_technician",
      "approval_request_owner_note": "Please review and approve at earliest convenience."
    },
    "timelines": [
      {
        "id": "9e15c8ba-...-tl01",
        "event_type": "submitted",
        "name": "Modification request submitted",
        "image": "",
        "occurred_at": "2026-05-04T10:30:00+00:00"
      }
    ]
  }
}
```

---

## 17. GET `/requests/transfers?page=1`

```json
{
  "success": true,
  "message": "Transfer requests retrieved successfully",
  "data": {
    "data": [
      {
        "id": "9e15c8ba-...-8001",
        "asset_name": "Coffee Machines #1",
        "status": "approved",
        "condition": "excellent",
        "date_and_time": "2026-05-08T09:15:00+00:00",
        "type": "to_branch"
      },
      {
        "id": "9e15c8ba-...-8002",
        "asset_name": "Refrigerators #2",
        "status": "pending",
        "condition": "need_attention",
        "date_and_time": "2026-05-10T14:45:00+00:00",
        "type": "to_branch"
      }
    ],
    "meta": {
      "current_page": 1,
      "last_page": 1,
      "total": 2
    }
  }
}
```

---

## 18. GET `/requests/transfers/{requestId}`

```json
{
  "success": true,
  "message": "Transfer request details retrieved successfully",
  "data": {
    "data": {
      "request_details": {
        "from": "Riyadh - Olaya",
        "to": "Jeddah - Tahlia",
        "request_date": "2026-05-08",
        "arrival_time": "2026-05-09T10:00:00+00:00",
        "transfer_id": "9e15c8ba-...-8001",
        "status": "approved"
      },
      "assets": [
        {
          "name": "Coffee Machines #1",
          "code": "FA-K3Z2M8VL",
          "image_url": "",
          "zone": { "id": "9e15c8ba-...-0002", "name": "Kitchen" },
          "type": { "id": "9e15c8ba-...-1002", "name": "Coffee Machines" },
          "status": "excellent",
          "value": "12500",
          "custody": "2 months",
          "transfer_reason": "Branch Jeddah - Tahlia needs equipment for new opening."
        }
      ],
      "skip_confirmation": false,
      "timelines": [
        {
          "id": "9e15c8ba-...-tl10",
          "event_type": "submitted",
          "name": "Transfer request submitted",
          "image": "",
          "occurred_at": "2026-05-08T09:15:00+00:00"
        },
        {
          "id": "9e15c8ba-...-tl11",
          "event_type": "approved",
          "name": "Transfer approved",
          "image": "",
          "occurred_at": "2026-05-09T07:00:00+00:00"
        }
      ]
    }
  }
}
```

---

## 19. POST `/requests/transfers/{requestId}/approve`

```json
{
  "success": true,
  "message": "Transfer approved successfully",
  "data": {
    "id": "9e15c8ba-...-8002",
    "status": "approved"
  }
}
```

---

## 20. GET `/requests/disposals?page=1`

```json
{
  "success": true,
  "message": "Disposal requests retrieved successfully",
  "data": {
    "data": [
      {
        "id": "9e15c8ba-...-8101",
        "asset_name": "Coffee Machines #1",
        "status": "approved",
        "date_and_time": "2026-05-07T08:00:00+00:00"
      },
      {
        "id": "9e15c8ba-...-8102",
        "asset_name": "Refrigerators #2",
        "status": "pending",
        "date_and_time": "2026-05-11T11:00:00+00:00"
      }
    ],
    "meta": {
      "current_page": 1,
      "last_page": 1,
      "total": 2
    }
  }
}
```

---

## 21. GET `/requests/disposals/{requestId}`

```json
{
  "success": true,
  "message": "Disposal request details retrieved successfully",
  "data": {
    "data": {
      "request_details": {
        "proposed_date": "2026-05-30",
        "proposed_time": "14:00",
        "disposal_method": "scrap",
        "selected_assets": 2,
        "status": "approved",
        "approved_on": "2026-05-09T07:00:00+00:00"
      },
      "assets": [
        {
          "name": "Coffee Machines #1",
          "code": "FA-K3Z2M8VL",
          "image_url": "",
          "zone": { "id": "9e15c8ba-...-0002", "name": "Kitchen" },
          "type": { "id": "9e15c8ba-...-1002", "name": "Coffee Machines" },
          "status": "excellent",
          "value": "12500",
          "custody": "2 months",
          "transfer_reason": "End of useful life; cost of repair exceeds replacement."
        }
      ],
      "timelines": [
        {
          "id": "9e15c8ba-...-tl20",
          "event_type": "submitted",
          "name": "Disposal request submitted",
          "image": "",
          "occurred_at": "2026-05-07T08:00:00+00:00"
        },
        {
          "id": "9e15c8ba-...-tl21",
          "event_type": "approved",
          "name": "Disposal approved",
          "image": "",
          "occurred_at": "2026-05-09T07:00:00+00:00"
        }
      ]
    }
  }
}
```

---

## Error envelope

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "asset_id": ["asset_id is required and must not be empty."]
  }
}
```

Status codes: `401` unauthenticated · `403` not a Branch Manager · `404` resource missing · `422` validation · `500` server error.

---

## Run seeder

```bash
php artisan db:seed --class="Modules\\FixedAssets\\Database\\Seeders\\FixedAssetsDatabaseSeeder"
```

Seeds per branch:
- 5 zones, 8 types (global)
- 20 fixed assets
- 3 pending receipts
- 4 modification requests (2 approved, 2 pending) + done_actions + attachments + timelines
- 2 transfer requests (1 approved, 1 pending) + items + documentation photos + timelines
- 2 disposal requests (1 approved, 1 pending) + items + visual evidence + timelines

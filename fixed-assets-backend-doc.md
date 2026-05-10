# Fixed Assets Feature - Backend API Documentation (Proposed)

> The fixed-assets feature currently uses mock data inside repositories. No real backend endpoints are wired yet. This document infers and proposes the backend APIs based on the current Flutter implementation and screen flows.

## Scope

- Feature area: Fixed Assets (Branch Manager)
- Screens covered:
  - Fixed Assets Dashboard
  - Search + Search Results
  - Receive Assets
  - Transfers & Disposal
  - Requests (Modification / Transfers / Disposals)
  - Modification Details
  - Modify Asset Status
  - Success screens (no backend)

## API Conventions (Proposed)

- Base path: `/branch-manager/fixed-assets`
- Auth: same as other branch-manager endpoints (bearer token)
- Content types:
  - JSON for standard requests
  - multipart/form-data for file uploads
- Timestamps: ISO-8601 strings (e.g. `2024-06-01T12:00:00Z`)
- Enum values are lowercase snake_case (as seen in Dart enums)

## Shared Objects (Response Objects)

### Shared Object: AssetLocationResponse

```json
{
  "id": "string",
  "name": "string"
}
```

Field rules:

- `id` (string, required)
- `name` (string, required)

### Shared Object: AssetSearchItemResponse

```json
{
  "id": "string",
  "name": "string",
  "code": "string",
  "image": "string",
  "location": {
    "id": "string",
    "name": "string"
  },
  "status": "excellent|need_attention|problem",
  "assigned_to": "string",
  "age": "string",
  "value": "string",
  "custody": "string"
}
```

Field rules:

- `id` (string, required)
- `name` (string, required)
- `code` (string, required)
- `image` (string, required; may be empty if no image)
- `location` (AssetLocationResponse, required)
- `status` (enum, required: `excellent`, `need_attention`, `problem`)
- `assigned_to` (string, required)
- `age` (string, required; UI expects readable text like `6 Months`)
- `value` (string, required; UI displays as numeric text)
- `custody` (string, required; UI expects readable text like `2 Months`)

### Shared Object: ReceiveAssetsItemResponse

```json
{
  "id": "string",
  "assetName": "string",
  "assetCode": "string",
  "assetImage": "string"
}
```

Field rules:

- `id` (string, required)
- `assetName` (string, required)
- `assetCode` (string, required)
- `assetImage` (string, required; may be empty)

### Shared Object: ZoneResponse

```json
{ "id": "string", "name": "string" }
```

### Shared Object: TypeResponse

```json
{ "id": "string", "name": "string" }
```

### Shared Object: ResponsibleEmployeeResponse

```json
{ "id": "string", "name": "string", "image": "string" }
```

### Shared Object: BranchResponse

```json
{ "id": "string", "name": "string", "image": "string" }
```

### Shared Object: PaginationMetaResponse

```json
{
  "current_page": 1,
  "last_page": 1,
  "total": 2
}
```

Field rules:

- All fields required
- Used by requests tabs (modifications/transfers/disposals)

### Shared Object: AttachmentResponse

```json
{
  "id": "string",
  "file_name": "string",
  "file_type": "string",
  "file_size": 0,
  "url": "string",
  "uploaded_at": "ISO-8601"
}
```

### Shared Object: TimelineItemResponse

```json
{
  "id": "string",
  "event_type": "string",
  "name": "string",
  "image": "string",
  "occurred_at": "ISO-8601"
}
```

Field rules:

- `event_type` should match `TimeLineEnum` values on backend
- `image` may be null/empty

### Shared Object: TimelineResponse

```json
{
  "timelines": [
    {
      "id": "string",
      "event_type": "string",
      "name": "string",
      "image": "string",
      "occurred_at": "ISO-8601"
    }
  ]
}
```

## Request/Response Objects (Detailed)

### Response Object: SearchSummaryResponse

```json
{
  "total_items": 20,
  "total_items_matching_search": 5,
  "total_items_matching_search_excellent_status": 2,
  "total_items_matching_search_maintenance_status": 2,
  "total_items_matching_search_problem_status": 1
}
```

### Response Object: SearchResponse

```json
{
  "asset_type_name": "Coffee Machines",
  "data": {
    "summary": {
      "total_items": 20,
      "total_items_matching_search": 5,
      "total_items_matching_search_excellent_status": 2,
      "total_items_matching_search_maintenance_status": 2,
      "total_items_matching_search_problem_status": 1
    },
    "items": [
      {
        "id": "string",
        "name": "string",
        "code": "string",
        "image": "string",
        "location": { "id": "string", "name": "string" },
        "status": "excellent|need_attention|problem",
        "assigned_to": "string",
        "age": "string",
        "value": "string",
        "custody": "string"
      }
    ]
  }
}
```

### Response Object: ModificationRequestsListResponse

```json
{
  "data": [
    { "id": "string", "asset_name": "string", "status": "pending|approved" }
  ],
  "meta": {}
}
```

### Response Object: TransfersRequestsListResponse

```json
{
  "data": [
    {
      "id": "string",
      "asset_name": "string",
      "status": "pending|approved",
      "condition": "excellent|need_attention|problem",
      "date_and_time": "ISO-8601",
      "type": "from_branch|from_finance|to_branch"
    }
  ],
  "meta": {}
}
```

### Response Object: DisposalsRequestsListResponse

```json
{
  "data": [
    {
      "id": "string",
      "asset_name": "string",
      "status": "pending|approved",
      "date_and_time": "ISO-8601"
    }
  ],
  "meta": {}
}
```

### Response Object: ModificationDetailsResponse

```json
{
  "status": "pending|approved",
  "asset_details": {
    "id": "string",
    "name": "string",
    "code": "string",
    "image": "string",
    "location": { "id": "string", "name": "string" },
    "status": "excellent|need_attention|problem",
    "assigned_to": "string",
    "age": "string",
    "value": "string",
    "custody": "string"
  },
  "modification_details": {
    "new_status": "excellent|need_attention|problem",
    "reason": "string",
    "attachment": {
      "id": "string",
      "file_name": "string",
      "file_type": "string",
      "file_size": 0,
      "url": "string",
      "uploaded_at": "ISO-8601"
    }
  },
  "required_actions": {
    "done_actions": ["initial_check", "cleaning"],
    "next_action": "need_technician|halt|replace",
    "approval_request_owner_note": "string"
  },
  "timelines": []
}
```

## Screen-by-Screen Flows (UI Behavior)

### 1) Fixed Assets Dashboard (FixedAssetsScreen)

- Shows summary counts and ongoing operations.
- Quick actions navigate to search, receive assets, transfers/disposal.

Backend needs:

- Overview counts for total/excellent/maintenance/problem.
- Ongoing operations progress (monthly count, handover session) if implemented later.

### 2) Search (FixedAssetsSearchScreen)

Inputs:

- General search filters: Zone, Type, Responsible Employee, Date.
- Quick search filters: update_today, update_yesterday, not_update_1_month, not_update_2_month, in_my_custody, under_maintenance.

Rules:

- If `search_type = general`, all four filters are required.
- If `search_type = quick`, only `quick_search_key` is required.

### 3) Search Results (FixedAssetsSearchResultScreen)

- Shows summary counts per status.
- List assets with selection, bulk actions, and per-item actions:
  - Settings (update location + image)
  - Transfer asset
  - Disposal request
  - Modify asset status

### 4) Receive Assets (FixedAssetsReceiveAssetsScreen)

- Loads list of pending receive assets.
- User selects items and fills required fields per item.

Rules:

- Only selected items are submitted.
- Each selected item must include: assigned zone, asset type, counts, and receipt photo.

### 5) Transfers & Disposal (FixedAssetsTransfersAndDisposalScreen)

- Request types: transfer_to_branch, disposal, external_transfer.
- User selects assets and provides conditional fields based on request type.

Rules:

- `assets` list is required for all request types.
- Transfer to branch requires recipient branch and per-asset transfer details.
- Disposal requires disposal date/time and per-asset disposal details.
- External transfer shows approval note in UI (no extra fields beyond `type` and `assets`).

### 6) Requests (FixedAssetsRequestsScreen)

- Paginated tabs: modifications, transfers, disposals.

### 7) Modification Details (FixedAssetsModificationDetailsScreen)

- Loads details by request id with timelines.

### 8) Modify Asset Status (FixedAssetsModifyAssetStatusScreen)

- Requires: new status, reason, documentation photo, done actions (at least one), next action, approval note.

## Proposed REST API Endpoints (Detailed)

### Reference Data

#### Get Zones

- **URL**: `/branch-manager/fixed-assets/zones`
- **Method**: `GET`
- **Response Object**: `ZonesListResponse`

```json
{ "data": [{ "id": "string", "name": "string" }] }
```

- **Used by**: Search filters, Receive Assets, Update Settings

#### Get Types

- **URL**: `/branch-manager/fixed-assets/types`
- **Method**: `GET`
- **Response Object**: `TypesListResponse`

```json
{ "data": [{ "id": "string", "name": "string" }] }
```

- **Used by**: Search filters, Receive Assets

#### Get Responsible Employees

- **URL**: `/branch-manager/fixed-assets/employees`
- **Method**: `GET`
- **Response Object**: `EmployeesListResponse`

```json
{ "data": [{ "id": "string", "name": "string", "image": "string" }] }
```

- **Used by**: Search filters (Responsible bottom sheet)

#### Get Branches

- **URL**: `/branch-manager/fixed-assets/branches`
- **Method**: `GET`
- **Response Object**: `BranchesListResponse`

```json
{ "data": [{ "id": "string", "name": "string", "image": "string" }] }
```

- **Used by**: Transfers & Disposal (recipient branch)

#### Get All Assets (for pickers)

- **URL**: `/branch-manager/fixed-assets/assets`
- **Method**: `GET`
- **Query Parameters Object**: `AssetsListQuery`

| Field  | Type   | Required | Rules                                                  |
| ------ | ------ | -------- | ------------------------------------------------------ |
| search | string | optional | Filters by asset name (UI uses name search in picker). |

- **Response Object**: `AssetsListResponse`

```json
{
  "data": [
    {
      "id": "string",
      "name": "string",
      "code": "string",
      "image": "string",
      "location": { "id": "string", "name": "string" },
      "status": "excellent|need_attention|problem",
      "assigned_to": "string",
      "age": "string",
      "value": "string",
      "custody": "string"
    }
  ]
}
```

- **Used by**: Transfers & Disposal asset picker

### Search

#### Search Assets

- **URL**: `/branch-manager/fixed-assets/assets/search`
- **Method**: `POST`
- **Request Object**: `AssetsSearchRequest`

| Field            | Type              | Required                            | Validation / Rules                                                                                                            |
| ---------------- | ----------------- | ----------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| search_type      | string            | required                            | `general` or `quick`.                                                                                                         |
| zone_id          | string            | required if `search_type = general` | Must be a valid zone id.                                                                                                      |
| type_id          | string            | required if `search_type = general` | Must be a valid type id.                                                                                                      |
| employee_id      | string            | required if `search_type = general` | Must be a valid employee id.                                                                                                  |
| date             | string (ISO-8601) | required if `search_type = general` | Date only or full datetime accepted.                                                                                          |
| quick_search_key | string            | required if `search_type = quick`   | One of: `update_today`, `update_yesterday`, `not_update_1_month`, `not_update_2_month`, `in_my_custody`, `under_maintenance`. |

- **Response Object**: `SearchResponse` (see above)
- **Used by**: Search screen -> Search results screen

#### Search by Image (UI button exists, not wired)

- **URL**: `/branch-manager/fixed-assets/assets/search-image`
- **Method**: `POST` (multipart)
- **Request Object**: `AssetsSearchByImageRequest`

| Field | Type | Required | Validation / Rules                       |
| ----- | ---- | -------- | ---------------------------------------- |
| image | file | required | Image only. Max size per backend limits. |

- **Response Object**: `SearchResponse`
- **Used by**: Search footer "Upload Image" button (currently no implementation)

### Receive Assets

#### Get Receive Assets List

- **URL**: `/branch-manager/fixed-assets/receive-assets`
- **Method**: `GET`
- **Response Object**: `ReceiveAssetsListResponse`

```json
{
  "data": [
    {
      "id": "string",
      "assetName": "string",
      "assetCode": "string",
      "assetImage": "string"
    }
  ]
}
```

- **Used by**: Receive Assets screen load

#### Confirm Receipt (Receive Assets)

- **URL**: `/branch-manager/fixed-assets/receive-assets/confirm`
- **Method**: `POST` (multipart)
- **Request Object**: `ReceiveAssetsRequest`

Top-level fields:

| Field | Type   | Required | Validation / Rules                                                                           |
| ----- | ------ | -------- | -------------------------------------------------------------------------------------------- |
| type  | string | required | Receive session type (`from_branch`, `from_finance`). Backend should define accepted values. |

Multipart fields (per item):

| Field                              | Type   | Required | Validation / Rules                            |
| ---------------------------------- | ------ | -------- | --------------------------------------------- |
| items[assetId][assetId]            | string | required | Asset id from `ReceiveAssetsItemResponse.id`. |
| items[assetId][assignedZoneId]     | string | required | Must be a valid zone id.                      |
| items[assetId][assetTypeId]        | string | required | Must be a valid type id.                      |
| items[assetId][assetCount]         | int    | required | Must be >= 0.                                 |
| items[assetId][excellentCount]     | int    | required | Must be >= 0.                                 |
| items[assetId][needAttentionCount] | int    | required | Must be >= 0.                                 |
| items[assetId][problemCount]       | int    | required | Must be >= 0.                                 |
| items[assetId][image]              | file   | required | Receipt photo.                                |

Rules:

- Only selected items are sent by the frontend.
- The `type` field is a top-level form field (not nested under `items`).
- Backend should validate that the sum of condition counts equals `assetCount` (recommended).
- The frontend uses `assetId` as the array key, not numeric indexes (e.g. `items[abc123][assetId]`). PHP-style indexed arrays are not used here.

### Update Asset Settings (Location + Image)

#### Update Asset Settings

- **URL**: `/branch-manager/fixed-assets/assets/{assetId}/settings`
- **Method**: `PATCH` (multipart)
- **Path Parameters Object**: `AssetIdPath`

| Field   | Type   | Required | Rules           |
| ------- | ------ | -------- | --------------- |
| assetId | string | required | Valid asset id. |

- **Request Object**: `UpdateAssetSettingsRequest`

| Field       | Type   | Required | Validation / Rules       |
| ----------- | ------ | -------- | ------------------------ |
| new_zone_id | string | required | Must be a valid zone id. |
| new_image   | file   | optional | Image file only.         |

### Transfers & Disposal

#### Create Transfer/Disposal Request

- **URL**: `/branch-manager/fixed-assets/requests/transfer-disposal`
- **Method**: `POST` (multipart)
- **Request Object**: `TransferOrDisposalRequest`

Common fields:

| Field | Type   | Required | Validation / Rules                                        |
| ----- | ------ | -------- | --------------------------------------------------------- |
| type  | string | required | `transfer_to_branch`, `disposal`, or `external_transfer`. |

Transfer-to-branch fields (required if `type = transfer_to_branch`):

| Field       | Type   | Required | Validation / Rules             |
| ----------- | ------ | -------- | ------------------------------ |
| branchId    | string | required | Recipient branch id.           |
| autoApprove | bool   | optional | If true, skip branch approval. |

Disposal fields (required if `type = disposal`):

| Field          | Type   | Required | Validation / Rules                              |
| -------------- | ------ | -------- | ----------------------------------------------- |
| disposalDate   | string | required | Date string (UI uses formatted date).           |
| disposalTime   | string | required | Time string (UI uses localized time).           |
| disposalMethod | string | optional | `scrap`, `sell`, `return_to_supplier`, `other`. |

Assets array (required for all types):

| Field                           | Type   | Required                                | Validation / Rules |
| ------------------------------- | ------ | --------------------------------------- | ------------------ |
| assets[i][assetId]              | string | required                                | Asset id.          |
| assets[i][transferReason]       | string | required if `type = transfer_to_branch` | Must be non-empty. |
| assets[i][documentationPhotos]  | file   | required if `type = transfer_to_branch` | Single file.       |
| assets[i][disposalReason]       | string | required if `type = disposal`           | Must be non-empty. |
| assets[i][conditionDescription] | string | required if `type = disposal`           | Must be non-empty. |
| assets[i][visualEvidence]       | file   | required if `type = disposal`           | Single file.       |

Rules:

- The UI requires acknowledgments (two checkboxes) before enabling submit, but these values are not sent in the current request. If backend needs them, add fields.
- For `external_transfer`, the UI shows approval note and does not require extra fields beyond `type` and `assets`.

#### Approve Transfer Request

- **URL**: `/branch-manager/fixed-assets/requests/transfers/{requestId}/approve`
- **Method**: `POST`
- **Path Parameters Object**: `RequestIdPath`

| Field     | Type   | Required | Rules                      |
| --------- | ------ | -------- | -------------------------- |
| requestId | string | required | Valid transfer request id. |

#### Transfer Request Details

- **URL**: `/branch-manager/fixed-assets/requests/transfers/{requestId}`
- **Method**: `GET`
- **Path Parameters Object**: `RequestIdPath`
- **Response Object**: `FixedAssetsTransferDetailsResponse`

```json
{
  "data": {
    "request_details": {
      "from": "string",
      "to": "string",
      "request_date": "string",
      "arrival_time": "string",
      "transfer_id": "string",
      "status": "string"
    },
    "assets": [
      {
        "name": "string",
        "code": "string",
        "image_url": "string",
        "zone": { "id": "string", "name": "string" },
        "type": { "id": "string", "name": "string" },
        "status": "excellent|need_attention|problem",
        "value": "string",
        "custody": "string",
        "transfer_reason": "string"
      }
    ],
    "skip_confirmation": false,
    "timelines": [
      {
        "id": "string",
        "event_type": "string",
        "name": "string",
        "image": "string",
        "occurred_at": "ISO-8601"
      }
    ]
  }
}
```

Field rules:

- `request_details.from` (string, required) — display name of the source branch/location
- `request_details.to` (string, required) — display name of the destination branch/location
- `request_details.request_date` (string, required) — formatted date string
- `request_details.arrival_time` (string, required) — formatted time string
- `request_details.transfer_id` (string, required) — human-readable transfer reference
- `request_details.status` (string, required) — maps to `FixedAssetsRequestsStatusStrategy`
- `assets[].zone` / `assets[].type` — use shared `ZoneResponse` / `TypeResponse` shapes
- `assets[].status` (enum, required) — `excellent`, `need_attention`, or `problem`
- `skip_confirmation` (bool, required) — if `true`, UI skips the confirmation step
- `timelines` — follows the shared `TimelineResponse` shape (see Shared Objects)

### Modification Requests

#### Create Modification Request (Modify Asset Status)

- **URL**: `/branch-manager/fixed-assets/requests/modifications`
- **Method**: `POST` (multipart)
- **Request Object**: `ModifyAssetStatusRequest`

| Field                       | Type     | Required | Validation / Rules                                                              |
| --------------------------- | -------- | -------- | ------------------------------------------------------------------------------- |
| asset_id                    | string   | required | Must be a valid asset id. Frontend currently sends empty string; must be fixed. |
| new_status                  | string   | required | `excellent`, `need_attention`, `problem`.                                       |
| reason                      | string   | required | Non-empty.                                                                      |
| attachment                  | file     | required | Documentation photo.                                                            |
| done_actions[]              | string[] | required | Must include at least one of `initial_check`, `cleaning`.                       |
| next_action                 | string   | required | `need_technician`, `halt`, `replace`.                                           |
| approval_request_owner_note | string   | required | Non-empty.                                                                      |

#### Get Modification Requests (Paginated)

- **URL**: `/branch-manager/fixed-assets/requests/modifications`
- **Method**: `GET`
- **Query Parameters Object**: `PaginationQuery`

| Field | Type | Required | Rules          |
| ----- | ---- | -------- | -------------- |
| page  | int  | optional | Defaults to 1. |

- **Response Object**: `ModificationRequestsListResponse`

#### Get Modification Details

- **URL**: `/branch-manager/fixed-assets/requests/modifications/{requestId}`
- **Method**: `GET`
- **Path Parameters Object**: `RequestIdPath`
- **Response Object**: `ModificationDetailsResponse`

### Transfers Requests (Paginated)

#### Get Transfer Requests

- **URL**: `/branch-manager/fixed-assets/requests/transfers`
- **Method**: `GET`
- **Query Parameters Object**: `PaginationQuery`
- **Response Object**: `TransfersRequestsListResponse`

### Disposals Requests (Paginated)

#### Get Disposal Requests

- **URL**: `/branch-manager/fixed-assets/requests/disposals`
- **Method**: `GET`
- **Query Parameters Object**: `PaginationQuery`
- **Response Object**: `DisposalsRequestsListResponse`

#### Disposal Request Details

- **URL**: `/branch-manager/fixed-assets/requests/disposals/{requestId}`
- **Method**: `GET`
- **Path Parameters Object**: `RequestIdPath`
- **Response Object**: `FixedAssetsDisposalsDetailsResponse`

```json
{
  "data": {
    "request_details": {
      "proposed_date": "string",
      "proposed_time": "string",
      "disposal_method": "string",
      "selected_assets": 0,
      "status": "string",
      "approved_on": "string|null"
    },
    "assets": [
      {
        "name": "string",
        "code": "string",
        "image_url": "string",
        "zone": { "id": "string", "name": "string" },
        "type": { "id": "string", "name": "string" },
        "status": "excellent|need_attention|problem",
        "value": "string",
        "custody": "string",
        "transfer_reason": "string"
      }
    ],
    "timelines": [
      {
        "id": "string",
        "event_type": "string",
        "name": "string",
        "image": "string",
        "occurred_at": "ISO-8601"
      }
    ]
  }
}
```

Field rules:

- `request_details.proposed_date` (string, required) — formatted date string
- `request_details.proposed_time` (string, required) — formatted time string
- `request_details.disposal_method` (string, required) — e.g. `scrap`, `sell`, `return_to_supplier`, `other`
- `request_details.selected_assets` (int, required) — total count of assets in this disposal request
- `request_details.status` (string, required) — maps to `FixedAssetsRequestsStatusStrategy`
- `request_details.approved_on` (string, optional/nullable) — populated once request is approved
- `assets[].zone` / `assets[].type` — use shared `ZoneResponse` / `TypeResponse` shapes
- `assets[].status` (enum, required) — `excellent`, `need_attention`, or `problem`
- `assets[].transfer_reason` (string, required) — disposal reason for the asset (maps to `transfer_reason` field in Dart model)
- `timelines` — follows the shared `TimelineResponse` shape (see Shared Objects)

### Asset Details / Preview

#### Get Asset Details

- **URL**: `/branch-manager/fixed-assets/assets/{assetId}`
- **Method**: `GET`
- **Path Parameters Object**: `AssetIdPath`
- **Response Object**: `AssetDetailsResponse`

```json
{
  "data": {
    "id": "string",
    "name": "string",
    "code": "string",
    "image": "string",
    "location": { "id": "string", "name": "string" },
    "status": "excellent|need_attention|problem",
    "assigned_to": "string",
    "age": "string",
    "value": "string",
    "custody": "string"
  }
}
```

- **Used by**: Search results -> Preview button (currently no action)

### Dashboard Overview

#### Get Overview Stats

- **URL**: `/branch-manager/fixed-assets/overview`
- **Method**: `GET`
- **Response Object**: `FixedAssetsOverviewResponse`

```json
{
  "total": 84,
  "excellent": 78,
  "maintenance": 5,
  "problem": 1
}
```

## Missing Backend APIs Discovered

- Image-based search endpoint for "Upload Image" button.
- Asset preview/details endpoint for "Preview" action.
- Transfer request approval endpoint for pending transfer requests.
- Transfer request details endpoint for "View Details" in transfers list. ✅ Defined — see `FixedAssetsTransferDetailsResponse`.
- Disposal request details endpoint for "View Details" in disposals list. ✅ Defined — see `FixedAssetsDisposalsDetailsResponse`.
- Dashboard overview stats endpoint (currently hardcoded in UI).

## Potential Backend/Frontend Integration Concerns

- Modify Asset Status request currently sends `asset_id` as an empty string in UI; backend should enforce and frontend must provide the selected asset id.
- Transfer/Disposal request uses mixed field casing (`branchId`, `autoApprove`, `disposalDate`, `disposalTime`) while other requests use snake_case. Consider standardizing backend parsing or align frontend.
- Acknowledgments are required in UI to enable submission but are not included in the request payload.
- Search summary response expects nested `data.summary` and `data.items`. Backend must follow this shape exactly.
- Pagination meta must include `current_page`, `last_page`, `total`.
- Receive Assets confirmation request includes a top-level `type` field and uses asset id as the array key (e.g. `items[assetId][...]`). This is less PHP-friendly than indexed arrays.

## Suggested Improvements

- Standardize all request/response keys to snake_case for backend consistency.
- Add missing `asset_id` in Modify Asset Status UI flow and include asset id in Update Settings path or body.
- Implement preview/details screen to avoid dead-end UI actions.
- Add search query (name/code) to search request; the search bar is currently ignored in backend contract.
- Consider adding realtime updates later for requests lists (not present in current UI).

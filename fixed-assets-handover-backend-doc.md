# Fixed Assets Handover Feature — Backend API Documentation (Proposed)

> The fixed-assets handover flow currently uses mock data inside repositories. No real backend endpoints are wired yet. This document infers and proposes the backend APIs based on the current Flutter implementation and screen flows.

---

## Scope

- **Feature area:** Fixed Assets Handover
- **Screens covered:**
  - Handover entry screen
  - Start handover
  - Join handover session
  - Active handover session
  - Session preview
  - Session signature
  - Session summary
  - Handover details
  - Success screens

---

## Handover Lifecycle Overview

Understanding the handover lifecycle is critical for correctly implementing status transitions, QR code generation, and response shapes. The diagram below shows how a handover session moves through its three states:

```
┌──────────────────────────────────────────────────────────────────────────┐
│                         HANDOVER LIFECYCLE                               │
│                                                                          │
│   [Sender calls POST /handover/start]                                    │
│          │                                                               │
│          ▼                                                               │
│   ┌──────────────────┐                                                   │
│   │ pending_approval │  ◄── Session + QR code created;                  │
│   └──────────────────┘      handoverId embedded in QR payload            │
│          │                                                               │
│          │  [Recipient scans QR, joins session, inspects assets,         │
│          │   then calls POST /signature/receiver]                        │
│          ▼                                                               │
│   ┌──────────────────┐                                                   │
│   │     pending      │  ◄── Recipient has signed                        │
│   └──────────────────┘                                                   │
│          │                                                               │
│          │  [Sender calls POST /signature/sender]                        │
│          │  [Recipient calls POST /sessions/{id}/complete]               │
│          ▼                                                               │
│   ┌──────────────────┐                                                   │
│   │    completed     │  ◄── Both parties signed; recipient completed     │
│   └──────────────────┘                                                   │
└──────────────────────────────────────────────────────────────────────────┘
```

### Status Transition Rules

| Transition         | Caller        | Endpoint                       | Resulting Status             |
| ------------------ | ------------- | ------------------------------ | ---------------------------- |
| Session created    | Sender        | `POST /handover/start`         | `pending_approval`           |
| Recipient signs    | **Recipient** | `POST /signature/receiver`     | `pending`                    |
| Sender signs       | Sender        | `POST /signature/sender`       | `pending` (no status change) |
| Handover completed | **Recipient** | `POST /sessions/{id}/complete` | `completed`                  |

> **Signing order:** The recipient must sign first (which triggers the `pending_approval` → `pending` transition). The sender can then sign. After both parties have signed, the recipient calls `/complete` to finalise the handover.

> **Important:** The `status` field must be consistent across all endpoints that return it: overview, details, summary, and signature responses. Any mismatch will cause UI inconsistencies.

---

## API Conventions (Proposed)

- **Base path:** `/branch-manager/fixed-assets/handover`
- **Auth:** Same as other branch-manager endpoints (bearer token)
- **Content types:**
  - `application/json` for standard requests
  - `multipart/form-data` for requests that attach photos
- **Timestamps:** ISO-8601 strings (e.g. `2025-12-25T10:00:00Z`)
- **Handover status values** map to `FixedAssetsHandoverEnum`:
  - `pending_approval`
  - `pending`
  - `completed`
- **Notification channel values** are sent as strings:
  - `whatsapp`
  - `sms`

---

## QR Code Contract

The QR code value must be a valid JSON object (UTF-8 string) containing the handover ID.

### Lifecycle Clarification

1. **Session creation (`pending_approval`)** — When the sender calls `/handover/start`, a new handover session is created and a QR code is generated. The `handoverId` is embedded directly in the QR JSON payload. At this point the handover status is `pending_approval`.

2. **Recipient signs (`pending`)** — The recipient scans the QR code to join the session, inspects assets, and then calls `POST /signature/receiver` themselves. When their signature is confirmed, the handover status transitions to `pending`. The sender can then sign via `POST /signature/sender`.

3. **Recipient completes (`completed`)** — When the recipient calls the complete endpoint to finalise the handover, the status transitions to `completed`.

### Required QR Payload

```json
{
  "handoverId": "handover_1"
}
```

**Field rules:**

- `handoverId` (string, required) — unique handover session identifier.
- The full QR value **must be JSON text**, not a plain string ID.
- The payload must **not** be wrapped in additional URL or query encoding.

**Usage:**

- Generated at session creation time (status `pending_approval`).
- Scanned by the join handover flow to retrieve and open the related handover session.

---

## Shared Response Objects

### HandoverIncludedZoneAssetResponse

```json
{
  "assetId": "string",
  "assetName": "string",
  "assetImageURL": "string",
  "totalQuantityInZone": 0,
  "totalExcellentConditionQuantityInZone": 0,
  "totalNeedAttentionConditionQuantityInZone": 0,
  "totalProblemConditionQuantityInZone": 0
}
```

| Field                                       | Type   | Required | Notes               |
| ------------------------------------------- | ------ | -------- | ------------------- |
| `assetId`                                   | string | yes      |                     |
| `assetName`                                 | string | yes      |                     |
| `assetImageURL`                             | string | yes      | May be empty string |
| `totalQuantityInZone`                       | int    | yes      |                     |
| `totalExcellentConditionQuantityInZone`     | int    | yes      |                     |
| `totalNeedAttentionConditionQuantityInZone` | int    | yes      |                     |
| `totalProblemConditionQuantityInZone`       | int    | yes      |                     |

---

### HandoverIncludedZoneResponse

```json
{
  "zoneID": "string",
  "zoneName": "string",
  "assetsInZone": [
    {
      "assetId": "string",
      "assetName": "string",
      "assetImageURL": "string",
      "totalQuantityInZone": 0,
      "totalExcellentConditionQuantityInZone": 0,
      "totalNeedAttentionConditionQuantityInZone": 0,
      "totalProblemConditionQuantityInZone": 0
    }
  ]
}
```

---

### HandoverIncludedZonesResponse

```json
{
  "totalIncludedItems": 0,
  "totalValueOfIncludedZones": 0,
  "includedZones": [
    {
      "zoneID": "zone_1",
      "zoneName": "Zone 1",
      "assetsInZone": [
        {
          "assetId": "asset_1",
          "assetName": "Laptop 01",
          "assetImageURL": "https://cdn.example.com/assets/laptop-01.png",
          "totalQuantityInZone": 12,
          "totalExcellentConditionQuantityInZone": 8,
          "totalNeedAttentionConditionQuantityInZone": 3,
          "totalProblemConditionQuantityInZone": 1
        }
      ]
    }
  ]
}
```

| Field                       | Type   | Required |
| --------------------------- | ------ | -------- |
| `totalIncludedItems`        | int    | yes      |
| `totalValueOfIncludedZones` | number | yes      |
| `includedZones`             | array  | yes      |

---

### StartHandoverRequest

```json
{
  "recipientEmployeeId": "string",
  "note": "string",
  "includedAssetIds": ["string"],
  "sendInvitations": ["sms"]
}
```

| Field                 | Type     | Required | Notes                                           |
| --------------------- | -------- | -------- | ----------------------------------------------- |
| `recipientEmployeeId` | string   | yes      |                                                 |
| `note`                | string   | yes      | Must be non-empty                               |
| `includedAssetIds`    | string[] | yes      | At least one asset ID                           |
| `sendInvitations`     | string[] | yes      | Values from `PurchasingOfficerNotificationEnum` |

---

### JoinHandoverSessionDetailsResponse

Returned when the recipient fetches session details before joining. The session is still in `pending_approval` status at this point.

```json
{
  "sessionId": "string",
  "sessionCode": "string",
  "sessionImageUrl": "string",
  "handoverByName": "string",
  "handoverByImageUrl": "string",
  "branchName": "string",
  "sessionDate": "ISO-8601",
  "totalZones": 0,
  "totalAssets": 0,
  "totalValue": 0,
  "note": "string",
  "includedZones": [
    {
      "zoneID": "zone_1",
      "zoneName": "Zone 1",
      "assetsInZone": [
        {
          "assetId": "asset_1",
          "assetName": "Laptop 01",
          "assetImageURL": "https://cdn.example.com/assets/laptop-01.png",
          "totalQuantityInZone": 12,
          "totalExcellentConditionQuantityInZone": 8,
          "totalNeedAttentionConditionQuantityInZone": 3,
          "totalProblemConditionQuantityInZone": 1
        }
      ]
    }
  ]
}
```

> `sessionDate` must be emitted as an ISO-8601 string. `includedZones` uses the shared `HandoverIncludedZoneResponse` shape.

---

### ActiveHandoverSessionDetailsResponse

```json
{
  "sessionId": "string",
  "fromEmployeeName": "string",
  "toEmployeeName": "string",
  "zones": [
    {
      "zoneId": "zone_1",
      "zoneName": "Zone 1",
      "assetsCount": 2,
      "isZoneApproved": false,
      "assets": [
        {
          "assetId": "asset_1",
          "assetName": "Laptop 01",
          "assetCode": "AC-1001",
          "assetImageUrl": "https://cdn.example.com/assets/laptop-01.png",
          "currentQty": 5,
          "newQty": 5,
          "recipientInspection": "excellent",
          "recipientNote": "",
          "recipientPhotoPath": ""
        }
      ]
    }
  ]
}
```

---

### ActiveHandoverZoneResponse

```json
{
  "zoneId": "string",
  "zoneName": "string",
  "assetsCount": 0,
  "isZoneApproved": false,
  "assets": []
}
```

---

### ActiveHandoverAssetResponse

```json
{
  "assetId": "string",
  "assetName": "string",
  "assetCode": "string",
  "assetImageUrl": "string",
  "currentQty": 0,
  "newQty": 0,
  "recipientInspection": "excellent",
  "recipientNote": "string",
  "recipientPhotoPath": "string"
}
```

| Field                 | Type   | Required | Notes                                                              |
| --------------------- | ------ | -------- | ------------------------------------------------------------------ |
| `recipientInspection` | string | yes      | Accepted values: `excellent`, `need_attention`, `problem`          |
| `newQty`              | int    | no       | Optional                                                           |
| `recipientNote`       | string | no       | Required if `recipientInspection` is `need_attention` or `problem` |
| `recipientPhotoPath`  | string | no       | Required if `recipientInspection` is `problem`                     |

---

### ActiveHandoverSessionPreviewResponse

```json
{
  "sessionId": "string",
  "iAmSigned": false,
  "progressItems": [
    { "label": "Asset 1", "percent": 100 },
    { "label": "Asset 2", "percent": 100 },
    { "label": "Total", "percent": 100 }
  ],
  "timeSpent": "0:58:13",
  "remainingPercent": 100,
  "totalAssets": 0,
  "acceptedAssets": 0,
  "noteAssets": 0,
  "rejectedAssets": 0,
  "acceptedList": [
    {
      "assetId": "asset_1",
      "assetName": "Laptop 01",
      "assetCode": "AC-1001",
      "assetImageUrl": "https://cdn.example.com/assets/laptop-01.png",
      "zoneName": "Zone 1",
      "typeName": "Equipment",
      "value": 28500,
      "age": "9 Months",
      "handoverDescription": "Excellent | 100% | Weekly"
    }
  ],
  "noteList": [
    {
      "assetId": "asset_2",
      "assetName": "Printer 01",
      "assetCode": "AC-1002",
      "assetImageUrl": "https://cdn.example.com/assets/printer-01.png",
      "zoneName": "Zone 2",
      "typeName": "Printer",
      "value": 14900,
      "age": "10 Months",
      "handoverDescription": "Need Attention | 80% | Monthly"
    }
  ],
  "rejectedList": [
    {
      "assetId": "asset_3",
      "assetName": "Scanner 01",
      "assetCode": "AC-1003",
      "assetImageUrl": "https://cdn.example.com/assets/scanner-01.png",
      "zoneName": "Zone 3",
      "typeName": "Scanner",
      "value": 8700,
      "age": "6 Months",
      "handoverDescription": "Problem | 0% | Weekly"
    }
  ]
}
```

---

### ActiveHandoverPreviewProgressItem

```json
{ "label": "string", "percent": 0 }
```

---

### ActiveHandoverPreviewAsset

```json
{
  "assetId": "string",
  "assetName": "string",
  "assetCode": "string",
  "assetImageUrl": "string",
  "zoneName": "string",
  "typeName": "string",
  "value": 0,
  "age": "string",
  "handoverDescription": "string"
}
```

---

### ActiveHandoverSessionSignatureResponse

Returned when polling the signature state. This endpoint is called periodically by the UI after a party signs. When the recipient signs, the backend must transition the handover status from `pending_approval` to `pending`.

```json
{
  "progressItems": [
    { "label": "Asset 1", "percent": 100 },
    { "label": "Asset 2", "percent": 100 },
    { "label": "Total", "percent": 100 }
  ],
  "sender": { "name": "Saad", "isSigned": true },
  "receiver": { "name": "Kareem", "isSigned": false }
}
```

> When `receiver.isSigned` flips to `true`, the handover status transitions to `pending`.

---

### ActiveHandoverSessionSummaryResponse

Returned after both parties have signed. The status at this point should be `pending` (both signed) or `completed` (recipient has completed the handover).

```json
{
  "sessionId": "string",
  "status": "pending_approval|pending|completed",
  "date": "string",
  "duration": "string",
  "assetsCount": 0,
  "value": 0,
  "acceptedCount": 0,
  "acceptedPercentage": 0,
  "noteCount": 0,
  "rejectedCount": 0,
  "photoTakenCount": 0,
  "recordedDiscrepancies": [
    {
      "assetId": "asset_1",
      "assetName": "Laptop 01",
      "assetCode": "AC-1001",
      "assetImageUrl": "https://cdn.example.com/assets/laptop-01.png",
      "zoneName": "Zone 1"
    }
  ],
  "sender": { "name": "Saad", "isSigned": true },
  "senderSignedAt": "2025-12-25 10:00:00",
  "receiver": { "name": "Kareem", "isSigned": true },
  "receiverSignedAt": "2025-12-25 11:00:00"
}
```

---

### RecordedDiscrepancyResponse

```json
{
  "assetId": "string",
  "assetName": "string",
  "assetCode": "string",
  "assetImageUrl": "string",
  "zoneName": "string"
}
```

---

### HandoverDetailsResponse

The `status` field reflects which lifecycle stage the handover is currently in. `completedDetails` is `null` until the status reaches `completed`.

```json
{
  "handoverId": "string",
  "status": "pending_approval|pending|completed",
  "initiatedDetails": {
    "qrCodeImageUrl": "https://cdn.example.com/assets/handover-qr.png",
    "sessionCode": "HSN-251225-001",
    "receiver": {
      "receiverName": "Kareem",
      "receiverImageUrl": "https://cdn.example.com/profiles/kareem.png",
      "receiverNumber": "+966 50123789"
    },
    "zones": [
      { "zoneName": "Zone 1", "assetsCountHandedOver": 10 },
      { "zoneName": "Zone 2", "assetsCountHandedOver": 11 }
    ],
    "note": "Please inspect the assets carefully.",
    "notifications": ["sms", "whatsapp"]
  },
  "recipientName": "string",
  "completedDetails": {
    "date": "2025-12-25",
    "duration": "1h 15m",
    "assetsCount": 50,
    "value": 485000,
    "acceptedCount": 45,
    "acceptedPercentage": 90,
    "noteCount": 3,
    "rejectedCount": 2,
    "photoTakenCount": 10,
    "recordedDiscrepancies": [
      {
        "assetId": "asset_1",
        "assetName": "Laptop 01",
        "assetCode": "AC-1001",
        "assetImageUrl": "https://cdn.example.com/assets/laptop-01.png",
        "zoneName": "Zone 1"
      }
    ],
    "sender": { "name": "Saad", "isSigned": true },
    "senderSignedAt": "2025-12-25 10:00:00",
    "receiver": { "name": "Kareem", "isSigned": true },
    "receiverSignedAt": "2025-12-25 11:00:00"
  },
  "timeLine": {
    "timelines": [
      {
        "id": "timeline_1",
        "event_type": "handover_started",
        "name": "Handover started",
        "image": "https://cdn.example.com/events/handover-started.png",
        "occurred_at": "2025-12-25T09:30:00Z"
      }
    ]
  }
}
```

**Field rules:**

| Field                | Notes                                                                           |
| -------------------- | ------------------------------------------------------------------------------- |
| `status`             | Reflects current lifecycle stage: `pending_approval`, `pending`, or `completed` |
| `initiatedDetails`   | Always present once the session is created                                      |
| `completedDetails`   | **Nullable** — omitted or `null` until status is `completed` or `pending`       |
| `timeLine.timelines` | Follows the shared `TimeLineResponse` shape                                     |

---

## Request / Response Objects (Detailed)

### OverviewHandoverResponse

```json
{
  "id": "string",
  "status": "pending_approval|pending|completed",
  "recipientName": "string"
}
```

---

### FixedAssetsOverviewResponse

```json
{
  "handover": {
    "id": "handover_1",
    "status": "pending_approval|pending|completed",
    "recipientName": "Kareem"
  },
  "totalAssets": 500,
  "totalAssetsExcellent": 300,
  "totalAssetsMaintenance": 150,
  "totalAssetsProblem": 50
}
```

| Field                            | Notes                                                           |
| -------------------------------- | --------------------------------------------------------------- |
| `handover`                       | **Nullable** when there is no active handover session           |
| `handover.id`                    | Required when present; the active handover ID                   |
| `handover.status`                | Maps to the handover status enum                                |
| `handover.recipientName`         | Display name shown in the UI card                               |
| `totalAssets` + condition totals | Required; always returned even when there is no active handover |

---

## Screen-by-Screen Flows

### 1. Handover Entry Screen

Shows the current handover overview card and the included zones summary. Lets the user choose zones, recipient employee, note, and invitation types before starting a session.

**Backend needs:**

- Overview stats for the handover card (`FixedAssetsOverviewResponse`)
- Included zones grouped by zone with counts and value (`HandoverIncludedZonesResponse`)
- Recipient employee data from an employee picker endpoint

---

### 2. Start Handover

User selects a recipient employee, one or more assets (grouped by zones), a note, and invitation channels.

**Rules:**

- `recipientEmployeeId` is required.
- `note` is required and non-empty.
- `includedAssetIds` must contain at least one asset ID.
- `sendInvitations` accepts values from `PurchasingOfficerNotificationEnum`.
- On success, the backend creates the session, generates a QR code with `handoverId` embedded, and returns `sessionId` + `sessionCode`.
- **Resulting status: `pending_approval`.**

---

### 3. Join Handover Session

Loads session details before joining. Shows the session code, sender info, branch name, included zones, and session note. Lets the recipient join the session.

**Rules:**

- `sessionId` is required to fetch join details.
- Joining the session carries no extra payload beyond the session ID.
- QR scan input must decode to JSON containing `handoverId`.
- The handover is still in `pending_approval` status when the recipient first views the join screen.

---

### 4. Active Handover Session

Shows zone-by-zone assets. Allows updating inspection, note, photo, and new quantity per asset. Allows approving a single zone or all zones.

**Rules:**

- `status_filter` can be used to filter the details view. Values used by the frontend: `global`, `rejected`.
- `recipientPhotoPath` is a local file path in app state and must be uploaded as multipart file data.

---

### 5. Session Preview

Shows progress items and separates assets into accepted, noted, and rejected lists. Shows whether the current user has already signed.

**Backend needs:**

- Progress percentages for the timeline/progress widgets.
- Separate asset lists for `accepted`, `noteList`, and `rejectedList` states.

---

### 6. Session Signature

Shows sender and receiver signature status. The UI refreshes the signature state after each signing action.

**Lifecycle note:** The recipient calls `POST /signature/receiver` to sign first. This transitions the handover status from `pending_approval` to `pending`. The sender can then call `POST /signature/sender`. Both signatures must be completed before the recipient can call `/complete`.

---

### 7. Session Summary

Shows final counts and percentages, recorded discrepancies, and sender/receiver signatures with timestamps. By this stage the status should be `pending` (both signed) or `completed`.

---

### 8. Handover Details

Displays the initiated state and, when available, the completed state. Displays timelines for the handover lifecycle.

**Lifecycle note:** `completedDetails` must remain `null` until the status is `completed`. Clients must not assume the field is always present.

---

### 9. Success Screens

No additional backend contract is required unless the success screen needs a refreshed session or summary payload.

**Lifecycle note:** Once recipient completion is confirmed via `/sessions/{sessionId}/complete`, the backend must transition the handover status to `completed`.

---

## Proposed REST API Endpoints

### Overview / Entry

#### GET `/branch-manager/fixed-assets/overview`

Returns overview stats for the handover card and asset condition summary.

- **Response:** `FixedAssetsOverviewResponse`
- **Used by:** Handover entry screen and overview card

---

#### GET `/branch-manager/fixed-assets/handover/included-zones`

Returns included zones grouped by zone with asset counts and values. Supports optional filtering by asset status.

- **Response:** `HandoverIncludedZonesResponse`
- **Used by:** Handover entry screen and active handover session (filtered views)

##### Query Parameters

| Parameter       | Type   | Required | Values               | Default  |
| --------------- | ------ | -------- | -------------------- | -------- |
| `status_filter` | string | no       | `global`, `rejected` | `global` |

##### `status_filter` Behaviour

| Value                 | Effect                                                                                                                                                                                           |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `global` (or omitted) | Returns all zones and all assets within them, regardless of inspection result.                                                                                                                   |
| `rejected`            | Returns only assets whose `recipientInspection` was `problem` in the most recent completed handover. Zones that have no such assets after filtering are **excluded entirely** from the response. |

> Filtering is applied at the asset level first, then empty zones are dropped. The `totalIncludedItems` and `totalValueOfIncludedZones` totals must reflect only the filtered set, not the full session totals.

```json
{
  "totalIncludedItems": 0,
  "totalValueOfIncludedZones": 0,
  "includedZones": [
    {
      "zoneID": "zone_1",
      "zoneName": "Zone 1",
      "assetsInZone": [
        {
          "assetId": "asset_1",
          "assetName": "Laptop 01",
          "assetImageURL": "https://cdn.example.com/assets/laptop-01.png",
          "totalQuantityInZone": 12,
          "totalExcellentConditionQuantityInZone": 8,
          "totalNeedAttentionConditionQuantityInZone": 3,
          "totalProblemConditionQuantityInZone": 1
        }
      ]
    }
  ]
}
```

---

### Start Handover

#### POST `/branch-manager/fixed-assets/handover/start`

Creates a new handover session. The backend must generate a QR code with the `handoverId` embedded at this point, and the initial status must be `pending_approval`.

- **Request:** `StartHandoverRequest`
- **Response:** `StartHandoverResponse`

| Request Field         | Type     | Required | Validation                                      |
| --------------------- | -------- | -------- | ----------------------------------------------- |
| `recipientEmployeeId` | string   | yes      | Must be a valid recipient employee ID           |
| `note`                | string   | yes      | Non-empty                                       |
| `includedAssetIds`    | string[] | yes      | At least one valid asset ID                     |
| `sendInvitations`     | string[] | yes      | Values from `PurchasingOfficerNotificationEnum` |

```json
{ "sessionId": "string", "sessionCode": "string" }
```

> Both `sessionId` and `sessionCode` must be returned so the join screen can surface them immediately.

---

### Join Handover Session

#### GET `/branch-manager/fixed-assets/handover/sessions/{sessionId}/join-details`

Fetches session details for the recipient before they join. The session is in `pending_approval` status at this point.

- **Path param:** `sessionId`
- **Response:** `JoinHandoverSessionDetailsResponse`

---

#### POST `/branch-manager/fixed-assets/handover/sessions/{sessionId}/join`

Joins the handover session. No additional payload is currently required by the frontend.

- **Path param:** `sessionId`
- The backend may return the active session ID or a refreshed session summary.

---

### Active Handover Session

#### GET `/branch-manager/fixed-assets/handover/sessions/{sessionId}/details`

Returns zone-by-zone asset details for the active session.

- **Path param:** `sessionId`
- **Response:** `FixedAssetsHandoverIncludedZonesResponse`

##### Response Shape: `FixedAssetsHandoverIncludedZonesResponse`

This endpoint returns the shared `FixedAssetsHandoverIncludedZonesResponse` shape extended with per-asset handover-specific fields.

```json
{
  "totalIncludedItems": 2,
  "totalValueOfIncludedZones": 43400,
  "includedZones": [
    {
      "zoneID": "zone_1",
      "zoneName": "Zone 1",
      "assetsInZone": [
        {
          "assetId": "asset_1",
          "assetName": "Laptop 01",
          "assetImageURL": "https://cdn.example.com/assets/laptop-01.png",
          "totalQuantityInZone": 12,
          "totalExcellentConditionQuantityInZone": 8,
          "totalNeedAttentionConditionQuantityInZone": 3,
          "totalProblemConditionQuantityInZone": 1,
          "assetCode": "AC-1001",
          "currentQty": 5,
          "newQty": 5,
          "recipientInspection": "excellent",
          "recipientNote": "",
          "recipientPhotoUrl": "https://cdn.example.com/uploads/photo-ac1001.jpg"
        }
      ]
    }
  ]
}
```

**Extended asset fields (in addition to the base `HandoverIncludedZoneAssetResponse` fields):**

| Field                 | Type   | Nullable | Notes                                                                                                   |
| --------------------- | ------ | -------- | ------------------------------------------------------------------------------------------------------- |
| `assetCode`           | string | no       | Asset code shown in the UI (e.g. `AC-1001`)                                                             |
| `currentQty`          | int    | no       | Quantity recorded at session creation                                                                   |
| `newQty`              | int    | yes      | Recipient-updated quantity; `null` if not yet edited                                                    |
| `recipientInspection` | string | yes      | One of `excellent`, `need_attention`, `problem`; `null` if not yet inspected                            |
| `recipientNote`       | string | yes      | Free-text note left by the recipient; `null` or empty if none                                           |
| `recipientPhotoUrl`   | string | yes      | **Remote URL** of the uploaded photo (see photo upload contract below); `null` if no photo was attached |

##### `recipientPhotoPath` → `recipientPhotoUrl` — Photo Upload Contract

The Flutter app holds `recipientPhotoPath` as a **local file path** in its state. It is never a remote URL. When submitting asset data (via approve-zone), the photo is sent as a `multipart/form-data` file field. The backend must:

1. Accept the file from the multipart field `items[assetId][photo]`.
2. Upload or store the file and resolve a publicly accessible URL.
3. Persist that URL against the asset record.
4. Return it as `recipientPhotoUrl` (a remote HTTPS URL) in this response.

The frontend must never send `recipientPhotoPath` as a JSON string value. The backend must never expect a file path string in JSON; it must always read the binary from the multipart stream.

> **Naming note:** The request field is `photo` (multipart file). The response field is `recipientPhotoUrl` (remote URL). These are intentionally different names to avoid confusion between upload input and read output.

---

#### POST `/branch-manager/fixed-assets/handover/sessions/{sessionId}/approve-zone` _(multipart)_

Approves a single zone with per-asset inspection data. Photos must be sent as multipart file uploads.

- **Path param:** `sessionId`

| Top-level Field | Type   | Required |
| --------------- | ------ | -------- |
| `sessionId`     | string | yes      |
| `zoneId`        | string | yes      |

| Per-asset Multipart Field             | Type   | Required | Notes                                |
| ------------------------------------- | ------ | -------- | ------------------------------------ |
| `items[assetId][assetId]`             | string | yes      |                                      |
| `items[assetId][recipientInspection]` | string | yes      |                                      |
| `items[assetId][recipientNote]`       | string | no       | Required only when a note is present |
| `items[assetId][newQty]`              | int    | no       | New quantity if edited               |
| `items[assetId][photo]`               | file   | no       | Receipt photo if attached            |

---

#### POST `/branch-manager/fixed-assets/handover/sessions/{sessionId}/approve-all`

Approves all zones in the session at once.

- **Path param:** `sessionId`

---

#### POST `/branch-manager/fixed-assets/handover/sessions/{sessionId}/signature/receiver`

Called by the **recipient** to submit their signature. **This transitions the handover status from `pending_approval` to `pending`.** After this call, the sender is unblocked to sign via `/signature/sender`.

- **Path param:** `sessionId`
- **Caller:** Recipient

---

#### POST `/branch-manager/fixed-assets/handover/sessions/{sessionId}/signature/sender`

Called by the **sender** to submit their signature. The sender may only sign after the recipient has signed (i.e. status must be `pending`). This call does not change the handover status.

- **Path param:** `sessionId`
- **Caller:** Sender
- **Precondition:** Status must be `pending` (recipient has already signed)

---

#### GET `/branch-manager/fixed-assets/handover/sessions/{sessionId}/signature`

Returns the current signature state for both parties. The UI polls this endpoint after a signing action.

- **Path param:** `sessionId`
- **Response:** `ActiveHandoverSessionSignatureResponse`

> When `receiver.isSigned` becomes `true`, the status transitions to `pending` and the sender is unblocked to sign. The `/complete` endpoint becomes available to the recipient only after both `sender.isSigned` and `receiver.isSigned` are `true`.

---

#### GET `/branch-manager/fixed-assets/handover/sessions/{sessionId}/preview`

Returns progress items and the accepted / noted / rejected asset lists.

- **Path param:** `sessionId`
- **Response:** `ActiveHandoverSessionPreviewResponse`

---

#### GET `/branch-manager/fixed-assets/handover/sessions/{sessionId}/summary`

Returns the final session summary including counts, discrepancies, and signature timestamps.

- **Path param:** `sessionId`
- **Response:** `ActiveHandoverSessionSummaryResponse`

---

#### POST `/branch-manager/fixed-assets/handover/sessions/{sessionId}/complete`

Called by the **recipient** to finalise the handover. **This transitions the handover status from `pending` to `completed`.**

- **Path param:** `sessionId`
- **Caller:** Recipient
- **Precondition:** Both the recipient and sender must have signed (both `isSigned` flags are `true`)

> After this call, `completedDetails` in `HandoverDetailsResponse` must no longer be `null`.

---

### Handover Details

#### GET `/branch-manager/fixed-assets/handover/{handoverId}`

Returns the full historical record for a handover, including initiated details, completed details (if available), and the event timeline.

- **Path param:** `handoverId`
- **Response:** `HandoverDetailsResponse`

| Field                | Notes                                       |
| -------------------- | ------------------------------------------- |
| `initiatedDetails`   | Always present once the session was created |
| `completedDetails`   | Nullable until status is `completed`        |
| `timeLine.timelines` | Uses the shared `TimeLineResponse` shape    |

---

### File / Timeline Shapes

#### TimeLineResponse

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

---

## Real-Time Events (Pusher)

The backend uses Pusher to push state changes to connected clients instantly, eliminating the need to poll for status updates or signature changes. The Flutter app must subscribe to the relevant channel when it enters any active handover screen and unsubscribe when it leaves.

---

### Channel Naming

All handover events are broadcast on a **private session channel**:

```
private-handover-session.{sessionId}
```

> Use a private channel (not public) so that Pusher authenticates the subscriber against the bearer token before allowing the connection. Only the sender and recipient of that specific session should be able to subscribe.

---

### Events

#### `handover.status.changed`

Broadcast whenever the handover status transitions. The client must react by refreshing the relevant screen or navigating to the next step.

**Triggered by:**

- `POST /signature/receiver` → status transitions from `pending_approval` to `pending`
- `POST /sessions/{id}/complete` → status transitions from `pending` to `completed`

**Payload:**

```json
{
  "handoverId": "string",
  "sessionId": "string",
  "status": "pending_approval|pending|completed"
}
```

| Field        | Type   | Notes                                                     |
| ------------ | ------ | --------------------------------------------------------- |
| `handoverId` | string | The parent handover ID                                    |
| `sessionId`  | string | The active session ID                                     |
| `status`     | string | New status: `pending_approval`, `pending`, or `completed` |

**Client behaviour:**

| New Status  | Recommended Client Action                                      |
| ----------- | -------------------------------------------------------------- |
| `pending`   | Unblock the sender's sign button; refresh the signature screen |
| `completed` | Navigate both parties to the success / summary screen          |

---

#### `handover.signature.receiver`

Broadcast when the **recipient** successfully submits their signature via `POST /signature/receiver`.

**Payload:**

```json
{
  "sessionId": "string",
  "receiver": {
    "name": "string",
    "isSigned": true,
    "signedAt": "ISO-8601"
  }
}
```

| Field               | Type   | Notes                               |
| ------------------- | ------ | ----------------------------------- |
| `sessionId`         | string | The active session ID               |
| `receiver.name`     | string | Display name of the recipient       |
| `receiver.isSigned` | bool   | Always `true` when this event fires |
| `receiver.signedAt` | string | ISO-8601 timestamp of the signature |

**Client behaviour:** The sender's device receives this event and should immediately enable the sender's sign button and update the signature status UI without requiring a manual refresh.

---

#### `handover.signature.sender`

Broadcast when the **sender** successfully submits their signature via `POST /signature/sender`.

**Payload:**

```json
{
  "sessionId": "string",
  "sender": {
    "name": "string",
    "isSigned": true,
    "signedAt": "ISO-8601"
  }
}
```

| Field             | Type   | Notes                               |
| ----------------- | ------ | ----------------------------------- |
| `sessionId`       | string | The active session ID               |
| `sender.name`     | string | Display name of the sender          |
| `sender.isSigned` | bool   | Always `true` when this event fires |
| `sender.signedAt` | string | ISO-8601 timestamp of the signature |

**Client behaviour:** The recipient's device receives this event and should update the signature status UI to show that the sender has signed. Once both `receiver.isSigned` and `sender.isSigned` are `true`, the recipient's complete button should become active.

---

### Event → Status Transition Mapping (Quick Reference)

| Pusher Event                  | Triggered By REST Call         | Status Before      | Status After |
| ----------------------------- | ------------------------------ | ------------------ | ------------ |
| `handover.signature.receiver` | `POST /signature/receiver`     | `pending_approval` | —            |
| `handover.status.changed`     | `POST /signature/receiver`     | `pending_approval` | `pending`    |
| `handover.signature.sender`   | `POST /signature/sender`       | `pending`          | —            |
| `handover.status.changed`     | `POST /sessions/{id}/complete` | `pending`          | `completed`  |

> `handover.signature.receiver` and `handover.signature.sender` carry signature details only and do not signal a status change. `handover.status.changed` is the authoritative event for status transitions and always fires alongside the signature event when the transition occurs.

---

### Flutter Integration Notes

- Subscribe to `private-handover-session.{sessionId}` as soon as the active handover session screen is mounted.
- Unsubscribe from the channel when the screen is disposed to avoid stale listeners.
- On receiving `handover.status.changed` with `status: completed`, navigate both parties away from the session screen — do not wait for a REST poll.
- Pusher events must not replace the REST signature endpoints; they are complementary. The REST call is the source of truth; the Pusher event is the delivery mechanism for the resulting state change.
- If the Pusher connection drops, fall back to polling `GET /sessions/{sessionId}/signature` at a reasonable interval until reconnected.

---

## Status Transition Summary (Quick Reference)

| Action            | Caller        | Endpoint                       | Status Before      | Status After          |
| ----------------- | ------------- | ------------------------------ | ------------------ | --------------------- |
| Start handover    | Sender        | `POST /handover/start`         | —                  | `pending_approval`    |
| Recipient signs   | **Recipient** | `POST /signature/receiver`     | `pending_approval` | `pending`             |
| Sender signs      | Sender        | `POST /signature/sender`       | `pending`          | `pending` (no change) |
| Complete handover | **Recipient** | `POST /sessions/{id}/complete` | `pending`          | `completed`           |

> The recipient must sign before the sender can sign. The recipient can only call `/complete` once both parties have signed.

---

## Missing Backend APIs

- Session creation response must return both `sessionId` and `sessionCode` for the join flow.

---

## Backend / Frontend Integration Concerns

- `sendInvitations` is serialized as enum strings via `toString()`, so the backend must accept string values exactly as emitted by the app.
- `includedAssetIds` is sent as a list of strings and should be validated as a set of unique asset IDs.
- `recipientPhotoPath` is a local path in Flutter state, not a remote URL — the backend must accept multipart file uploads and return a resolved remote URL (`recipientPhotoUrl`) in the details response. See the photo upload contract under the `/details` endpoint.
- `sessionDate` is parsed as a `DateTime` in the frontend and must be emitted in ISO-8601 format.
- `completedDetails` can be `null` in `HandoverDetailsResponse` while the handover is still in `pending_approval` or `pending` state — keep the field optional until the status is `completed`.
- `status` values must match `pending_approval`, `pending`, and `completed` consistently across the overview, details, signature, and summary endpoints.

---

## Suggested Improvements

- Return the session code from start handover so the join screen can surface it immediately after creation.
- Standardise all handover endpoints to a single resource naming scheme — either `handover` or `handover-sessions` — before implementation begins.
- Add explicit backend validation for `newQty` and `recipientInspection` values at the API boundary.
- Consider including an approval audit trail in the summary response if the summary screen later needs richer history data.
- Consider adding a dedicated status endpoint (`GET /sessions/{sessionId}/status`) so clients can poll the current status without re-fetching full detail responses.

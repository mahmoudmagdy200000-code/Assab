# Fixed Assets Preview History Asset API

## Screen

`FixedAssetsPreviewHistoryAssetScreen`

This screen shows a full history timeline for a single fixed asset, including:

- Historical photos
- Status history
- Transfer history
- Custody history
- Financial information
- Generate report action

---

## Endpoint

#### GET `/branch-manager/fixed-assets/assets/{assetId}/history`

Returns the full history preview for a single fixed asset.

- **Path Parameters Object:** `AssetIdPath`
- **Response Object:** `FixedAssetPreviewAssetHistoryResponse`

##### Path Parameters

| Field   | Type   | Required | Rules           |
| ------- | ------ | -------- | --------------- |
| assetId | string | required | Valid asset id. |

##### Response Shape

```json
{
  "data": {
    "assetId": "string",
    "assetName": "string",
    "assetCode": "string",
    "location": "string",
    "assetImage": "string",
    "photos": {
      "photos": [
        {
          "id": "string",
          "imageUrl": "string",
          "updatedBy": "string",
          "date": "string",
          "isCurrent": true
        }
      ],
      "totalCount": 0
    },
    "statusHistory": {
      "statuses": [
        {
          "id": "string",
          "date": "string",
          "note": "string",
          "status": "string",
          "statusColor": "string"
        }
      ],
      "totalCount": 0
    },
    "transfers": {
      "transfers": [
        {
          "id": "string",
          "date": "string",
          "label": "string",
          "fromLocation": "string",
          "toLocation": "string"
        }
      ],
      "totalCount": 0
    },
    "custody": {
      "currentCustody": {
        "id": "string",
        "custodianName": "string",
        "duration": "string",
        "role": "string"
      },
      "previousCustody": [
        {
          "id": "string",
          "custodianName": "string",
          "duration": "string",
          "role": "string"
        }
      ],
      "performanceScore": "string"
    },
    "financial": {
      "items": [
        {
          "label": "string",
          "value": "string"
        }
      ]
    }
  }
}
```

##### Field Notes

- `photos`, `statusHistory`, `transfers`, `custody`, and `financial` are optional at the top level and may be omitted when no data is available.
- `photos.photos[].isCurrent` marks the active image shown in the summary card and the photo list.
- `statusHistory.statuses[].statusColor` is used by the UI to render the colored status indicator.
- `custody.currentCustody` may be `null` when no current custodian exists.

---

## Generate Report Endpoint

#### POST `/branch-manager/fixed-assets/assets/{assetId}/history/report`

Generates a history report for the selected asset from the preview screen.

- **Path Parameters Object:** `AssetIdPath`
- **Request Body:** none required for the current UI flow
- **Response Object:** `FixedAssetPreviewGenerateReportResponse`

##### Path Parameters

| Field   | Type   | Required | Rules           |
| ------- | ------ | -------- | --------------- |
| assetId | string | required | Valid asset id. |

##### Response Shape

```json
{
  "data": {
    "reportId": "string",
    "fileName": "asset-history-report.pdf",
    "downloadUrl": "https://cdn.example.com/reports/asset-history-report.pdf",
    "generatedAt": "2026-01-01T10:00:00Z"
  }
}
```

##### Field Notes

- The frontend uses this endpoint from the bottom bar "Generate Report" action.
- `downloadUrl` can be a direct file URL or a temporary signed URL.
- If the backend prefers to stream a file instead of returning a URL, the response contract can be updated to match the implementation, but the asset id path parameter should remain the same.

---

## Implementation Notes

- The screen is currently static in the UI and should be wired to the history endpoint above.
- The response model already matches the nested section structure expected by the screen.
- The generate report endpoint is not yet wired in the presentation layer and should be attached to the bottom action button once implemented.

# Fixed Assets Handover Requests API

## Endpoint

GET `/branch-manager/fixed-assets/requests/handovers`

---

## Description

This endpoint returns a list of fixed assets handover requests for branch managers, including request type, status, timestamps, and pagination metadata.

---

## Response

### 200 OK

```json
{
  "data": {
    "data": [
      {
        "id": "string",
        "type": "sender | receiver",
        "status": "pending | pending_approval | completed",
        "created_at": "2026-01-01T10:00:00Z",
        "updated_at": "2026-01-01T12:00:00Z"
      }
    ],
    "meta": {
      "current_page": 1,
      "last_page": 10,
      "per_page": 15,
      "total": 150
    }
  }
}
```

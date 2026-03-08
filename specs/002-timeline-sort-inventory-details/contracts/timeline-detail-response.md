# Contract: Timeline in Detail Responses

**Feature**: 002-timeline-sort-inventory-details  
**Scope**: All API detail endpoints that include a timeline (Expense, Purchase, Custody, Inventory).

## Response requirement

Any endpoint that returns **entity details** and includes a timeline MUST include a key (e.g. `timelines` or `timeline`) whose value is an **array** of timeline entries. The array MUST be ordered by event date/time **ascending** (oldest first). If there are no events, the value MUST be `[]`.

## Timeline entry shape (unified)

Every timeline entry in the array MUST have the following structure. This applies to Expense, Purchase, Custody, and Inventory detail/timeline responses.

| Field         | Type   | Required | Description |
|---------------|--------|----------|-------------|
| `id`          | string | Yes      | Unique identifier of the timeline record (UUID). |
| `event_type`  | string | Yes      | Event type code (e.g. `expense_created`, `session_submitted`, or module-specific enum value). |
| `name`        | string | Yes      | Display name of the actor who performed the action. |
| `image`       | string \| null | Yes | Full URL of the actor's image, or `null` if none. |
| `occurred_at` | string | Yes      | Datetime when the event occurred, format `Y-m-d H:i:s`. |

## Example (Expense-style, same shape for all modules)

```json
{
  "success": true,
  "data": {
    "id": "...",
    "status": "...",
    "timelines": [
      {
        "id": "019cce2b-90be-733b-b84f-be5434bd9415",
        "event_type": "expense_created",
        "name": "Ahmed Al-Saud",
        "image": null,
        "occurred_at": "2026-03-08 15:58:07"
      },
      {
        "id": "...",
        "event_type": "expense_submit",
        "name": "Ahmed Al-Saud",
        "image": "https://...",
        "occurred_at": "2026-03-08 16:00:00"
      }
    ]
  }
}
```

## Endpoints in scope

- **Expense**: Branch-manager and brand-owner expense **detail** and **timeline** endpoints.
- **Purchase**: Order/return/goods-receipt/variance/compensatory **detail** responses and **timeline** endpoints.
- **Custody**: Custody request **detail** response (already returns `timeline`; ensure order asc).
- **Inventory**: Daily quick session **detail** (getSession) and monthly inventory **detail** (show); both MUST include `timelines` in the above shape, ordered asc. Dedicated timeline endpoints (e.g. getTimelines) MUST also return asc order.

## Out of scope

- Changing field names or adding optional fields to the unified shape is out of scope for this feature; any such change would require a separate contract update.

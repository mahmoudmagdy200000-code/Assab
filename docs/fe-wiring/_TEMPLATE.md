# FE Wiring — T## <Module Name> (<الاسم بالعربي>)

> Backend module status: ✅ ready for integration
> Base URL: `/api/v1` · Auth: `Authorization: Bearer <token>` (Sanctum, guard `asab`)
> Response envelopes (`AsabResponse`): success = bare JSON object, or `{ "data": [...], "meta": {...} }`
> for paginated lists. Errors: `{ "error": { "code": "...", "message": "..." }, "requestId": "..." }`
> with HTTP status — 401 unauthenticated, 403 role/tenant denied, 404 not found (incl. cross-tenant),
> 409 conflict/locked (e.g. `OP_ALREADY_FINAL`), 422 validation.
> Binary downloads (Excel/PDF/CSV) stream raw with no JSON envelope.
> Money: all amounts are integer **halalas** — divide by 100 and format `ar-SA` client-side.

## Screens covered (prototype mapping)

| Prototype screen | Endpoints |
|---|---|
| «...» | ... |

## Conventions for this module

- Roles allowed: `...` (middleware `asab.role:...`)
- Idempotency: send `Idempotency-Key: <uuid>` header on POST/PATCH marked ⚠️.
- Pagination: `?page=1&per_page=25` → `meta: {current_page, last_page, per_page, total}`.
- Common filters: `?branch_id=&brand_id=&status=&date_from=&date_to=&search=`.

---

## Endpoints

### 1. <Purpose — e.g. "Dashboard KPIs">

`GET /api/v1/...`  · Roles: `accountant,head`

Query params:

| Param | Type | Required | Notes |
|---|---|---|---|
| `...` | string | no | ... |

Response `200`:

```json
{
  "success": true,
  "message": "...",
  "data": { }
}
```

Notes for FE:
- Feeds component «...».
- Field `x` maps to UI label «...».

### 2. <Mutation — e.g. "Approve operation"> ⚠️ idempotent

`POST /api/v1/...`  · Roles: `...`

Body:

```json
{ }
```

| Field | Type | Required | Validation |
|---|---|---|---|
| `...` | ... | yes | ... |

Response `200` / errors: `409` when operation is `final-approved` (locked), ...

---

## Enums (key ↔ Arabic label)

| Enum | Key | Arabic |
|---|---|---|
| status | `pending` | معلق / قيد المراجعة |

## Realtime / polling

- Poll `GET ...` every Ns, or subscribe to channel `...` (if broadcasting enabled).

## Test accounts / seed data

- Role `...`: `email` / seeded via `...Seeder`.

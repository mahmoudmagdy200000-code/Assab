# FE Wiring — T08 Shifts — «الورديات»

> Backend module status: ✅ ready for integration · Delivered 2026-07-12 · Tests: 21 green (14 foundation + 7 chain)
> Base URL: `/api/v1` · Auth: `Authorization: Bearer <token>` (Sanctum, guard `asab`)
> Envelopes (`AsabResponse`): success = bare object, or `{ "data": [...], "meta": {...} }` for lists.
> Errors: `{ "error": { "code", "message", "messageAr", "details" }, "requestId" }` + HTTP status.
> Money: integer **halalas**. `live` returns `{ active[], overdue[], kpis }` at top level (not paginated).
> **Read [FE-T03](FE-T03-operations-pipeline.md) first** — a closed shift becomes a `module_key='shifts'` pipeline operation (SHF-); approve/reject/final-approve/audit-trail come from there.

## The close → approval chain (read before wiring the close button)

A shift close does **not** finalize the shift. It walks the same pipeline as every other record:

```
active/late ──close──▶ pending_review  (+ SHF- operation, status=pending)
                          │
              accountant approve ──▶ operation approved
                          │
                 head final-approve ──▶ shift CLOSED  + «خصم فرق كاش» debit to the cashier
                          │
                 head reject (reason) ──▶ shift reopens ACTIVE, no charge
```

- **Expected cash is server-derived** (`opening float + sales − card − aggregator`). Stop sending `salesSystem` — it is ignored.
- On final-approval a **cash shortage** (negative variance) is auto-charged to the shift's cashier («خصم فرق كاش»), unless the accountant recorded an explicit split first (§6). Surplus/zero → no charge.

## Two surfaces + roles

| Path | Role | Notes |
|---|---|---|
| `/v1/company/me/shifts*` | `accountant` | canonical dashboard; idempotency + audit |
| `/v1/accountant/shifts/live\|history` | `accountant,head` | platform-SPA twin |
| `/v1/company/me/branch/shifts/*` | `branch` | open/close/active (BRM-5) |
| `/v1/company/me/brands/{id}/shift-config` | `accountant` | timings editable here only |

## Screens covered

| Prototype (ACC-6 / BRM-5) | Endpoint |
|---|---|
| Live tab: cards + late banner | `GET /accountant/shifts/live` → `active[]` / `overdue[]` |
| 4 KPI tiles | same call → `kpis` |
| Setup tab: per-brand hours + float | `GET /company/me/shifts/configs` · `PUT /company/me/brands/{id}/shift-config` |
| Close flow (expected vs actual) | `POST .../shifts/{id}/close` → SHF- op |
| Cash-gap split | `POST .../shifts/{id}/variance-allocations` |
| History tab + Excel | `GET .../shifts/history` · `GET .../shifts/export` |
| Contact modal (tel/wa) | shift `cashierPhone` / `whatsapp` |
| BRM open/active/close | `POST/GET /company/me/branch/shifts/*` |
| BRM read-only timings | `GET /company/me/branch/settings` → `shiftConfig.readOnly` |

---

## 1. Config — `GET /company/me/shifts/configs` · `PUT /company/me/brands/{brandId}/shift-config`

Meeting N-shift model. PUT body (either form):
```json
{ "numShifts": 3, "durationHours": 8, "firstShiftStart": "06:00", "openingFloatHalalas": 50000 }
// or legacy: { "morningWindow": "06:00-14:00", "eveningWindow": "14:00-23:00", "openingFloatHalalas": 50000 }
```
Response (both GET rows and PUT):
```json
{ "brandId":"…", "brandName":"براند", "numShifts":3, "durationHours":8, "firstShiftStart":"06:00",
  "openingFloatHalalas":50000,
  "shifts":[ {"no":1,"name":"الأول","start":"06:00","end":"14:00","window":"06:00-14:00"}, … ],
  "morningWindow":"06:00-14:00", "eveningWindow":"14:00-22:00" }
```
`numShifts` 1–4; `firstShiftStart`/windows regex-validated (`HH:MM`); float defaults **50000** (500 SAR) when omitted; cross-company brand → 404; branch role → 403.

## 2. Open — `POST /company/me/branch/shifts/open` (role branch)

Body: `{ cashierEmpNumber? (alias cashierId), openingCashHalalas? }`. Resolves the cashier in the branch (404 if foreign), derives `shiftNo`/`shiftType` from the brand config, defaults the float from config. Double-open → `409 SHIFT_ALREADY_OPEN`. Returns the canonical shift shape (§4).

## 3. Live + history + KPIs

`GET /accountant/shifts/live`:
```json
{ "active":[ {shift…} ], "overdue":[ {shift with status:"late"} ],
  "kpis": { "openNow":2, "closedToday":5, "todaySalesHalalas":940000, "cashGapsPendingReview":1 } }
```
`GET /accountant/shifts/history?branchId=&shiftType=&dateFrom=&dateTo=&search=&page=&pageSize=` — paginated closed shifts (search matches cashier/supervisor name).

## 4. Canonical shift shape

```json
{ "id":"…", "branchId":"…", "branchName":"فرع أ",
  "cashierEmployeeId":"…", "cashierName":"محمد", "cashierPhone":"0551234567", "whatsapp":"wa.me/966551234567",
  "supervisorName":"خالد", "shiftNo":1, "shiftType":"الأول",
  "startedAt":"…", "endedAt":null, "status":"active", "statusLabelAr":"نشط",
  "isLate":false, "lateBannerAr":null,
  "ordersCount":12, "salesHalalas":940000, "openingFloatHalalas":50000,
  "cashExpectedHalalas":990000, "cashActualHalalas":null, "varianceHalalas":null }
```
Deprecated aliases still emitted: `supervisor`, `salesAmount`, `cashExpected`, `cashActual`, `variance`.

## 5. Close — `POST /company/me/shifts/{id}/close`

Body: `{ cashActualHalalas (alias cashInDrawer), cardTotalHalalas?, aggregatorTotalsHalalas?, notes? }`. **Do not send `salesSystem`.** Response = shift shape (`status:"pending_review"`) + `{ operationId, operationPublicId }`. Re-close → `409 SHIFT_ALREADY_CLOSED`. The accountant then approves the SHF op via `POST /company/me/operations/{operationId}/approve`; the head final-approves via the pipeline (FE-T03).

## 6. Cash-gap split — `POST /company/me/shifts/{id}/variance-allocations`

Body: `{ allocations: [ { employeeId|empNumber, amountHalalas } ] }`. Amounts must sum to `|variance|` (else 422). Optional — without it the whole gap auto-charges the cashier on final approval. Must be set **before** head final-approve.

## 7. Excel — `GET /company/me/shifts/export?format=xlsx|csv`

Binary stream. Columns include نوع الشفت + الكاشير. Branch-scoped (a brand-scoped accountant's file excludes other branches).

## Enums (key → labelAr)

- **status**: `active` نشط · `late` تأخير (banner «انتهى وقت الشفت — لم يُغلق الصندوق بعد») · `pending_review` بانتظار المراجعة · `closed` مغلق
- **shiftType**: sequential «الأول/الثاني/الثالث/الرابع» or `صباحي`/`مسائي`
- **ledger category**: `cash_variance` «خصم فرق كاش»

## Realtime

`operations.brand.{brandId}` / `reminders.branch.{branchId}` → `shift.opened|late|closed|reopened`. Late detection runs every 15 min (Asia/Riyadh) server-side.

## Deferred / notes

- Live `ordersCount`/`salesHalalas` are fed by the dashboard's own sales uploads (interim). The full POS feed rides the MOB-1.6 bridge, which is already wired: a **legacy cashier shift close** auto-creates the asab shift + SHF- op (`origin=mobile`) in the accountant inbox, deduped by `legacyShiftId`.
- Expected-cash formula assumes card/aggregator totals are supplied at close; if omitted they're 0 (all sales treated as cash).

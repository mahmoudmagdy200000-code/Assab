# FE Wiring — T09 Employees Ledger & Cash Custody — «حسابات الموظفين والعُهد النقدية»

> Backend module status: ✅ ready for integration · Delivered 2026-07-12 · Tests: 19 green (12 ledger + 7 custody)
> Base URL: `/api/v1` · Auth: `Authorization: Bearer <token>` (Sanctum, guard `asab`)
> Envelopes (`AsabResponse`): success = bare object, or `{ "data": [...], "meta": {...} }` for lists.
> Errors: `{ "error": { "code", "message", "messageAr", "details" }, "requestId" }` + HTTP status.
> Money: integer **halalas** everywhere (`balanceHalalas`, `amountHalalas`, …). Exports stream **binary** xlsx/csv (no envelope).
> All `/company/me/*` mutations require an `Idempotency-Key` header (`asab.idempotency`) and are audited; tenant comes from the token — no company id param anywhere.

## Two surfaces + roles

| Path | Role | Notes |
|---|---|---|
| `/company/me/employees*` | `accountant` | canonical portal ledger |
| `/company/me/cash-custody*` | `accountant, head` | **head owns تعزيز العهدة** — both roles admitted here |
| `/accountant/employees*`, `/accountant/cash-custody*` | `accountant, head` | platform-SPA twin (same handlers) |

**Canonical for the portal:** `GET /company/me/employees/{id}/movements` is the doc-conformance alias of the platform `GET /accountant/employees/{id}/statement` (identical payload). Same for the `employees` / `cash-custody` lists.

## Screens covered

| Prototype (ACC-7 / ACC-8 / HEAD-4) | Endpoint |
|---|---|
| كشف حساب الموظفين — master list (+balance chip, search) | `GET …/employees` |
| Statement detail (month picker, running balance, banner) | `GET …/employees/{id}/movements?month=` |
| «إضافة حركة» modal | `POST /accountant/employees/{id}/movements` |
| «تسوية الرصيد» | `POST …/employees/{id}/settle-balance` |
| Statement PDF/Excel | `GET …/employees/{id}/statement/export` |
| Payroll Excel | `GET …/employees/payroll/export?month=` |
| العُهد النقدية — cards + KPI header | `GET …/cash-custody` → rows + `meta.kpis` |
| Expandable txn ledger (month, running balance) | `GET …/cash-custody/{id}/transactions?month=` |
| «تعزيز عهدة» modal (head) / disbursement | `POST …/cash-custody/{id}/transactions` |
| Disbursement review (approve/reject) | `POST …/transactions/{txnId}/approve|reject` |
| «تسوية العهدة» | `POST …/cash-custody/{id}/settle` |
| Custody summary + monthly ledger Excel | `GET …/cash-custody/export` · `…/{id}/transactions/export` |

---

# A. Employee ledger (ACC-7)

## A1. List — `GET /company/me/employees`

Query: `page`, `pageSize` (≤100), `branchId`, `empNumber`, `q` (name search).
Response (`{data, meta}`):
```json
{ "data": [
  { "id":"…","empNumber":"1001","name":"محمد","phone":"0551234567","role":"كاشير",
    "branchId":"…","branchName":"فرع أ","monthlySalary":100000,"status":"active",
    "balanceHalalas":7000, "balanceCaption":{ "key":"creditor","labelAr":"دائن" } } ],
  "meta": { "page":1,"pageSize":20,"total":1,"totalPages":1 } }
```
`balanceHalalas` = Σcredit − Σdebit (one aggregate query, no N+1). Negative = employee owes.

## A2. Statement — `GET /company/me/employees/{id}/movements?month=YYYY-MM&page=&pageSize=`

`month` optional (omit = all history). Response:
```json
{ "employee": { "id":"…","name":"محمد","empNumber":"1001","branchId":"…" },
  "period": { "month":"2026-07","from":"2026-07-01","to":"2026-07-31" },
  "openingBalanceHalalas":5000, "closingBalanceHalalas":12000,
  "balance":12000, "balanceHalalas":12000,
  "balanceCaption": { "key":"creditor","labelAr":"دائن" },
  "autoDeductFromSalary": false,
  "totalCredit":15000, "totalDebit":3000, "movementCount":2,
  "movements": [
    { "id":"…","movementDate":"2026-07-05T…","description":"سلفة","movementType":"debit",
      "movementTypeLabelAr":"مدين","category":"advance","categoryLabelAr":"سلفة",
      "ref":"ADV-9F3A21","amountHalalas":3000,"amount":3000,"runningBalanceHalalas":12000 } ],
  "meta": { "page":1,"pageSize":50,"total":2,"totalPages":1 } }
```
- **Rows are newest-first**; each carries its `runningBalanceHalalas`. `openingBalanceHalalas` = net carried from before the month; `closingBalanceHalalas` = last running.
- **`autoDeductFromSalary`** = the employee's *standing* balance < 0 → render the banner «سيتم خصم الرصيد السالب من الراتب القادم». (v1 has no automated salary job — see backlog note; the payroll export is the materialization.)

## A3. Add movement — `POST /accountant/employees/{id}/movements` (platform surface)

Body: `{ movementType: "credit"|"debit", amount (int≥1), category, description, date? }`.
`category` is **required** and must be a **manual** key (A-Enums). System keys (`sales_variance`, `cash_variance`, `waste_charge`, `inventory_variance`) → **422** — those only enter through their operation flows. Unknown key → 422.
Response `201`: `{ id, category, categoryLabelAr, ref, amountHalalas, movementType }` (`ref` auto-generated, e.g. `ADV-…`).

## A4. Settle balance «تسوية الرصيد» — `POST /company/me/employees/{id}/settle-balance`

Body: `{ amountHalalas? }` (omit = clear the whole standing balance). Posts an opposing `settlement` movement (a debtor is cleared with a credit, a creditor with a debit). Balance already 0 → **409 `BALANCE_ALREADY_SETTLED`**. Amount > outstanding → 422.
Response: `{ id, employeeId, settledHalalas, movementType, category:"settlement", categoryLabelAr, ref, balanceHalalas, balanceCaption }`.

## A5. Exports

- `GET /company/me/employees/{id}/statement/export?month=&format=xlsx|csv` — per-employee ledger (rows mirror A2 + closing footer).
- `GET /company/me/employees/payroll/export?month=YYYY-MM&format=` — payroll. **Net = salary − Σdebits + Σcredits**; columns split السلف (advances) / الخصومات (other debits) / المكافآت (credits). Branch-scoped.

## A-Enums (key → labelAr, render verbatim)

- **Movement category** — *manual* (postable via A3): `advance «سلفة»`, `bonus «مكافأة»`, `absence «غياب»`, `receipts_shortage «نقص إيصالات»`, `settlement «تسوية»`, `deduction «خصم»`. *System* (read-only, posted by ops): `sales_variance «فرق مبيعات»`, `cash_variance «خصم فرق كاش»`, `waste_charge «خصم هدر»`, `inventory_variance «فرق جرد يومي»`.
- **Movement type**: `debit «مدين»` (employee owes) / `credit «دائن»`.
- **Balance caption**: `< 0 → «مديون للشركة»`, `> 0 → «دائن»`, `0 → «لا يوجد رصيد»`.

---

# B. Cash custody (ACC-8 / HEAD-4)

## B1. List — `GET /company/me/cash-custody?branchId=&q=&page=&pageSize=`

Response (`{data, meta}` with a KPI block in meta):
```json
{ "data": [
  { "id":"…","branchId":"…","branchName":"فرع أ","custodianName":"أمين",
    "amountHalalas":1000000,"usedHalalas":150000,"remainingHalalas":850000,
    "minAlertHalalas":500000,"usagePct":15.0,
    "status":"normal","statusLabelAr":"طبيعي","daysSinceSettlement":3,
    "transactions":[ … ] } ],
  "meta": { "page":1,"pageSize":20,"total":1,"totalPages":1,
            "kpis": { "activeCustodies":3, "pendingRequests":2, "nearDepletion":1 } } }
```
- **`status` is derived** (never trust a stored `active`): `remaining < 50000` (500 SAR) → `critical`; `remaining < minAlert` (default 500000 = 5,000 SAR) → `low`; else `normal`. Usage bar red > 85% (`usagePct`).
- **KPIs**: `activeCustodies` (count in scope), `pendingRequests` (pending settlement-requests + pending txns), `nearDepletion` (remaining < 500 SAR).

## B2. Monthly ledger — `GET /company/me/cash-custody/{id}/transactions?month=YYYY-MM&page=&pageSize=`

```json
{ "custody": { "id":"…","custodianName":"أمين","amountHalalas":700000,"usedHalalas":100000,"currentBalanceHalalas":600000 },
  "period": { "month":"2026-07","from":"…","to":"…" },
  "transactions": [
    { "id":"…","txnDate":"…","description":"صرف","txnType":"debit",
      "typeLabel":{ "key":"out","labelAr":"دائن - صادر" },"typeLabelAr":"دائن - صادر",
      "amountHalalas":100000,"amount":100000,"status":"approved","source":null,
      "runningBalanceHalalas":600000 } ],
  "meta": { "page":1,"pageSize":50,"total":2,"totalPages":1 } }
```
Newest-first; `runningBalanceHalalas` reconciles to `currentBalanceHalalas`. **Only approved txns move the balance**; pending rows carry it flat.

## B3. Add transaction (replenish / disburse) — `POST /company/me/cash-custody/{id}/transactions`  *(accountant, head)*

Body: `{ txnType: "credit"|"debit", amount (int≥1), description?, date?, status?, source? }`.
- `txnType=credit` = money **into** custody (تعزيز / replenish → raises `amountHalalas`); `debit` = disbursement (raises `usedHalalas`).
- `source`: `treasury` | `manual`. A `credit` + `source=treasury` with no description auto-labels «تعزيز عهدة من الخزينة».
- `status`: `approved` (default — applies the balance now) or `pending` (records a disbursement request, applies **nothing** until approved).
- A `debit` that exceeds `remaining` → **422 `CUSTODY_OVERDRAWN`**.
- Status is recomputed after any applied txn (may flip to `low`/`critical` → emits `custody.low_balance`).
Response `201`: `{ id, status, txnType, amountHalalas, source }`.

## B4. Approve / Reject a txn — `POST …/transactions/{txnId}/approve` · `…/reject`

- **approve**: applies the pending txn's balance effect **exactly once** (re-approving an approved txn is a safe no-op). A rejected txn → **409 `TXN_REJECTED`**. Overdraw re-checked at approval → 422.
- **reject** (body `{ reason }`, required): persists `reason`; if the txn was previously **approved**, its balance effect is **reversed**. Rejecting a pending txn undoes nothing.

## B5. Settlement request / Settle

- `POST …/cash-custody/{id}/settlement-request` → creates a pending `SettlementRequest` (drives the `pendingRequests` KPI).
- `POST …/cash-custody/{id}/settle` — body `{ newDepositHalalas? }`. Zeroes `used`, posts a settlement **debit** (closing the spent portion) + a **credit** for any new deposit to the ledger for audit, drains this custody's pending settlement requests (→ `approved`), recomputes status. Response `{ id, amountHalalas, usedHalalas:0 }`.

## B6. Exports

- `GET /company/me/cash-custody/export?branchId=&format=` — custody summary (incl. حد التنبيه + derived الحالة). Branch-scoped.
- `GET /company/me/cash-custody/{id}/transactions/export?month=&format=` — monthly ledger (rows mirror B2 + current-balance footer).

## B-Enums (key → labelAr)

- **Custody status**: `normal «طبيعي»` / `low «منخفض»` / `critical «حرج»`. (DB may still store `active` on untouched rows — **derive from the balance, do not hardcode**.)
- **Custody txn type**: DB stores `credit|debit`; UI labels `credit → «مدين - وارد»` (money in, incl. تعزيز), `debit → «دائن - صادر»` (disbursement). Backend ships `typeLabel`/`typeLabelAr` in B2.
- **Txn status**: `pending «قيد المراجعة»` / `approved «معتمد»` / `rejected «مرفوض»`.

## Thresholds & realtime

- `minAlertHalalas` default **500000** (5,000 SAR) → `low`; near-depletion `remaining < 50000` (500 SAR) → `critical` + KPI.
- Channel `reminders.branch.{branchId}` → `custody.low_balance` `{ custodyId, status, remainingHalalas }` when a txn drops a custody to low/critical.

## Notes / deferred

- **Employee picker reuse**: `GET /company/me/branches/{branchId}/employees/lookup?empNumber=` (from T04) — reuse for movement/allocation forms.
- **Auto-salary-deduction**: v1 = the `autoDeductFromSalary` banner flag + corrected payroll export math; no scheduled `SAL-` job (master-plan backlog).
- The platform surface names some money fields bare `amount` (same halalas unit); the company surface is fully `…Halalas`. Prefer the company surface.

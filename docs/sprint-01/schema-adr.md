# S1-03: Minimal Schema and Compatibility Decision (ADR)

**Status: Ready for review; not Accepted.** This is a source-verified design proposal, not implementation authority. It changes documentation only. No migration, backfill, seed, fixture, or application test was run for S1-03. High-impact implementation remains gated on S1-05 review.

## 1. Decision scope and evidence rules

This ADR corrects the published S1-03 proposal after audit findings R01, R05, and R07. Current code, migrations, routes, models, and consumers establish AS-IS. Business Rules v2.0 establish required TO-BE. Sprint Plan v2.0 limits this task to a concrete compatibility/migration decision. Historical audit findings identify questions to revalidate; they do not authorize implementation.

The relevant plan requires a limited adapter where it satisfies the money contract, additive schema only where revisions/receipt references/operation identity/idempotency truly require it, no edits to applied migrations, no global division by 100, no settings-based receipt backfill, explicit historical-unit review, and before/after count and total checks. This ADR proposes no SQL execution.

**Overall CHANGE / NO-CHANGE decision:** no change to existing monetary columns or monetary records; **NO MIGRATION** is required to reconcile legacy SAR with Admin halalas. For revision snapshots and separately confirmed receipt, propose new additive evidence tables only. Do not add unreviewed columns to the mutable legacy handover row. Idempotency/operation storage and shift-level uniqueness remain deferred until S1-05 plus data preflight. This is the smallest safe source-supported direction; implementation is not authorized here.

### Decision matrix

| Concern | Decision | Reason and boundary |
|---|---|---|
| Existing money storage and SAR/halala conversion | **ADAPTER ONLY** | Legacy Shift tables/models use SAR `DECIMAL`; Admin `shifts` money fields use integer halalas. `BridgeLegacyCashierShift::toHalalas()` performs the legacy-to-Admin conversion. `ShiftCloseService` consumes integer halalas. Do not alter either storage representation, add another ×100, or run a global `/100` update. |
| Handover revision identity and current-row lookup | **ADDITIVE MIGRATION REQUIRED** | Existing handover rows are mutable and have no revision identity. Proposed child revisions use the existing handover UUID as request identity and a unique revision number within that request. A shift-level unique index is not approved until duplicate/cardinality preflight and S1-05 business/API review resolve the current `hasOne` versus non-unique-data gap. |
| Immutable report snapshots and history retention | **ADDITIVE MIGRATION REQUIRED** | `cashier_shift_history` is a generic action/old/new text log, not a complete revision snapshot. Current rejection reset hard-deletes handover and report-detail rows. A new revision snapshot record is needed; schema alone cannot enforce immutability, so append-only service behavior and tests are also required. |
| Confirmed receipt identity and amount | **ADDITIVE MIGRATION REQUIRED** | Current requested handover amount/status does not provide a separate immutable confirmed amount/reference. Add a separate receipt record for forward confirmations; keep it distinct from request revisions. Never infer or backfill a receipt from settings, a requested amount, a legacy approval status, or an opening balance. |
| Idempotency and financial-operation identity | **DECISION DEFERRED TO S1-05** | Current idempotency middleware stores a globally unique key, user, method/path, response, status, and expiry, but no tenant/resource/revision or payload hash. `asab_operations` has a unique public ID, while source identity is nullable and not unique. S1-05 must select endpoint-specific identity and same-key/different-payload behavior before choosing the final columns/indexes. Do not add `operation_id` or an unscoped handover key now. |
| History preservation for legacy records | **ADDITIVE MIGRATION REQUIRED** | Preserve current report state as an explicitly identified initial snapshot only after preflight. Existing `cashier_shift_history` cannot reconstruct every prior report revision. Historical confirmed receipts cannot be synthesized; missing history remains an explicit gap. |
| API aliases, Dashboard/mobile compatibility, and revision/receipt DTOs | **DECISION DEFERRED TO S1-05** | Keep existing routes, response keys, and legacy SAR payloads during an expand/adapter phase. The exact additive revision/receipt fields and deprecation policy need the S1-05 API blueprint. No consumer is assumed to understand a new field today. |

## 2. Verified AS-IS money and compatibility boundary

### Storage and conversion

- Legacy `cashier_shifts` and related Shift models expose monetary values as `DECIMAL(...,2)` / Eloquent `decimal:2` values representing SAR. Relevant sources: `Modules/Shift/database/migrations/2025_10_09_152034_create_cashier_shifts_table.php`; `Modules/Shift/app/Models/CashierShift.php:51-63`.
- `cashier_shift_handovers.handover_amount` and `variance_amount` are `DECIMAL(12,2)` and `CashierShiftHandover` casts them to `decimal:2`: `Modules/Shift/database/migrations/2025_12_01_133846_create_cashier_shift_handovers_table.php:11-54`; `Modules/Shift/app/Models/CashierShiftHandover.php:40-58`.
- Admin `shifts.sales_amount`, `cash_expected`, and `cash_actual` are unsigned integer columns; `variance` is signed integer. `opening_float` is an unsigned integer. These fields carry halalas in the Admin path: `Modules/Admin/database/migrations/2026_06_05_000001_create_asab_ops_detail_tables.php:45-64`; `Modules/Admin/database/migrations/2026_07_12_000001_add_shift_cashier_and_type.php:18-25`; `Modules/Admin/app/Models/Shift.php:43-53`.
- `BridgeLegacyCashierShift` converts legacy SAR values through `toHalalas()` at `Modules/Admin/app/Listeners/BridgeLegacyCashierShift.php:80-98,152-155`. `ShiftCloseService::close()` reads integer `cashActualHalalas`, derives integer expected cash and variance, and persists those integers at `Modules/Admin/app/Services/ShiftCloseService.php:69-103`.
- Dashboard `../dashboard/artifacts/mockup-sandbox/src/api/money.ts:1-38` divides halalas for display and multiplies SAR by 100 for conversion; `../dashboard/artifacts/mockup-sandbox/src/api/types/company.ts:764-846` describes Admin shift values as integers/halalas (`route-map.md` § Dashboard and Mobile compatibility). The read-only mobile source `../AssabAPP/lib/features/shift_module_features/shift_management/data/data_source/shift_management_remote_data_source.dart:120-186` and request models send legacy numeric SAR-shaped fields; their `double` type alone does not declare a unit.

Therefore the prior proposal to multiply Admin `ShiftDetailResource` values by 100 or divide them before Admin persistence is incorrect. It would double-convert a value already in halalas. S1-03 makes **no money-column migration** and no global data rewrite. The observed legacy-to-Admin conversion boundary is `BridgeLegacyCashierShift::toHalalas()`; its current `(float)`/`round()` implementation is a source fact, not proof of exact decimal arithmetic. S1-02's target exact-decimal adapter, input precision/range, zero/null semantics, and tax rounding remain as recorded in `money-contract.md`; this ADR does not claim they are implemented or invent rules.

### R01 field-level monetary contract

| Table / field | SQL type, null/default; model cast | Meaning and stored/domain unit | Current API / Dashboard / mobile representation | Conversion owner and source |
|---|---|---|---|---|
| `asab_shifts.sales_amount` | `UNSIGNED BIGINT NOT NULL DEFAULT 0`; `Shift::$casts['sales_amount']='integer'` | Gross sales total in integer halalas. | Admin Presenter: `salesHalalas` and deprecated `salesAmount`, both the same integer. Dashboard `Shift` DTO treats `salesHalalas` as integer halalas. Legacy mobile does not consume this Admin DTO; it sends/reads SAR on legacy routes. | `BridgeLegacyCashierShift::toHalalas()` converts legacy SAR to halalas once before `ShiftCloseService`; Admin path performs integer math. Migration `2026_06_05_000001_create_asab_ops_detail_tables.php:45-64`; later bridge/model/presenter cited above and `Modules/Admin/app/Services/ShiftPresenter.php:60-72`. |
| `asab_shifts.cash_expected` | `UNSIGNED BIGINT NULL` (no default); integer cast | Expected drawer cash in halalas; `NULL` can mean not yet calculated, not zero. | `cashExpectedHalalas` plus deprecated `cashExpected`; Dashboard field is integer halalas and nullable in the API type. | Derived by `ShiftCloseService` using opening + cash share of sales. No conversion on native Admin close. Same migration; `ShiftPresenter.php:60-72`; `ShiftCloseService.php:69-103`. |
| `asab_shifts.cash_actual` | `UNSIGNED BIGINT NULL` (no default); integer cast | Physical drawer count in halalas. | `cashActualHalalas` plus deprecated `cashActual`; Dashboard sends/reads integer halalas. | Native close accepts `cashActualHalalas`. Legacy bridge synthesizes it from `cash_collected + opening_balance` after converting each legacy SAR field; that is not an independent physical count. |
| `asab_shifts.variance` | signed `BIGINT NULL` (no default); integer cast | Signed difference in halalas. | `varianceHalalas` plus deprecated `variance`; Dashboard uses signed halalas. | Calculated as actual minus expected by Admin close. No storage conversion. |
| `asab_shifts.opening_float` | `UNSIGNED BIGINT NULL` (no default); integer cast; added without default | Opening float in halalas; nullable is distinct from an explicitly confirmed zero. | Admin Presenter emits `openingFloatHalalas`; Dashboard contract uses integer halalas. Legacy schedule/API fields remain SAR. | Bridge converts legacy opening SAR once; native Admin opening remains integer halalas. `2026_07_12_000001_add_shift_cashier_and_type.php:18-25`. |
| `cashier_shifts.opening_balance`, `closing_balance`, `expected_balance`, `variance`, `total_sales`, `net_sales`, `vat_amount`, `cash_collected`, `card_payments` | Each `DECIMAL(12,2) NOT NULL DEFAULT 0.00`; `CashierShift` casts to `decimal:2` | Legacy report/cash-channel amounts in SAR. `cash_collected` is the cash sales channel, not an independent physical count. | Legacy controllers accept numeric SAR; legacy resources serialize SAR numbers/strings. Flutter compatibility models use legacy SAR numbers; omitted optional fields may be defaulted to zero by the active service. Dashboard native Admin DTO does not interpret these columns directly. | Only the legacy-to-Admin bridge converts to integer halalas. `Modules/Shift/database/migrations/2025_10_09_152034_create_cashier_shifts_table.php:19-43`; `CashierShift.php:51-63`; `ShiftDetailResource.php:155-245`; active inline validation in `ShiftEndController.php:39-108,145-164`. |
| `shift_sales_breakdown.amount` | `DECIMAL(12,2) NOT NULL` (no default); `ShiftSalesBreakdown` cast `decimal:2` | One delivery-app/channel line in SAR. | Legacy request/resource uses SAR; Dashboard/Admin operation payload uses integer `amountHalalas` / aggregator total halalas; mobile sends legacy SAR `aggregators[].amount`. | Bridge converts each line once. `2025_10_10_121058_create_shift_sales_breakdown_table.php`; `BridgeLegacyCashierShift.php:80-98`. |
| `shift_variance_details.variance_amount`, `assigned_amount` | `DECIMAL(12,2) NOT NULL`; `DECIMAL(12,2) NULL`; model casts decimal:2 | Report variance and optional assigned responsibility amounts in legacy SAR. | Legacy variance endpoints/resources and Flutter use numeric SAR. Not an Admin `asab_shifts` column. | No conversion in this table. Any Admin boundary adapter must be explicit and one-time. `Modules/Shift/database/migrations/2025_10_10_121139_create_shift_variance_details_table.php:14-30`. |
| `cashier_shift_handovers.handover_amount`, `variance_amount` | `DECIMAL(12,2) NOT NULL` and `DECIMAL(12,2) NOT NULL DEFAULT 0`; model casts `decimal:2` | Requested handover amount and reported variance, in SAR. These do not establish a confirmed receipt. | Legacy handover routes/resources and Flutter payload are SAR. Dashboard Admin `Shift` response does not make these fields halalas merely by alias. | A new receipt integer field is only a proposal; do not relabel or mutate these current SAR fields. Migration/model in §3. |
| `cashier_custody_transactions.amount`, `personal_ledger_transactions.amount` | Both `DECIMAL(12,2) NOT NULL`; model casts decimal:2 | Legacy custody/personal-ledger movement amounts in SAR. `related_handover_id` points at the mutable request row; it is not a revision/receipt identity. | Legacy custody/ledger consumers expose SAR-style amounts; no halala reinterpretation is established. | Future movements should reference an immutable receipt identity. Migrations: `Modules/Custody/database/migrations/2026_03_05_000001_create_cashier_custody_transactions_table.php:11-37`; `2025_10_09_151501_create_personal_ledger_transactions_table.php:11-37`. |
| `asab_operations.amount` | `UNSIGNED BIGINT NOT NULL DEFAULT 0` | Generic operation amount. In the native shift pipeline it is passed `sales_amount` halalas; do not infer a global unit for every module. | Admin operation payload has explicit `*Halalas` fields for shift values; Dashboard uses halalas on the native Shift contract. | No generic conversion. `Modules/Admin/database/migrations/2026_06_02_000001_create_asab_layer_tables.php:166-195`; `ShiftCloseService.php:94-103`. |

The source does not establish dedicated `asab_shifts` columns for net, VAT, card, or aggregator totals; those values are carried in the operation payload or represented on legacy Shift storage. Do not invent columns or assume every `asab_operations.amount` shares the shift unit. For zero/null/omission, retain the S1-02 findings: legacy DB defaults of zero do not prove explicit zero; Admin nullable actual/expected/opening fields can represent unset; Presenter emits nullable values and deprecated aliases; legacy resources sometimes cast null to `0` or use fallbacks. Mobile omitted optional fields are not proof of zero. `money-contract.md` and `route-map.md` contain the endpoint-specific field map and payload anchors; this ADR does not change them.

### Compatibility rule

Preserve existing legacy request and response shapes while any additive revision/receipt design is introduced. New explicitly named halala fields may be added only through the reviewed API blueprint. Do not silently reinterpret an existing un-suffixed number as halalas, remove deprecated aliases, or infer receipt confirmation from a legacy status. Dashboard native Admin halala fields and mobile legacy SAR fields remain separate contracts until an approved adapter maps them.

## 3. Verified handover storage and cardinality gap

`cashier_shift_handovers` currently has UUID primary key `id`, FK `cashier_shift_id` (cascade on shift delete), polymorphic recipient, requested `handover_amount`, `variance_amount`, files/notes/date/time, status, rejection metadata, approver metadata, and `handed_over_at`. Its migration creates ordinary indexes on `(handover_to_id, handover_to_type)`, `(approved_by_id, approved_by_type)`, `status`, `handover_date`, and `cashier_shift_id`; there is no unique constraint, revision/receipt/idempotency column, or FK on `related_handover_id` ledger references (`2025_12_01_133846_create_cashier_shift_handovers_table.php:11-54`). The separate `shift_handover_status` table's unique shift key is not uniqueness on the handover table.

`CashierShift::handover()` is a `HasOne` relation (`Modules/Shift/app/Models/CashierShift.php:117-124`), but that relationship does not constrain the database or order multiple matching rows. `HandoverService::recordHandover()` inserts a row (`Modules/Shift/app/Services/HandoverService.php:38-123`); approval and cashier acceptance use `where(cashier_shift_id)->first()` (`:188-235,604-620`); rejection/edit paths update rows by shift (`:443-490,541-590`); and the rejection reset deletes all matching handover rows (`:383-416`). `CashierShiftResource` also has a direct fallback query by shift. These are reader/writer impacts, not proof that duplicate rows currently exist.

The existing UUID row ID is the only source-backed candidate stable request identity. It is not proven that multiple rows for one shift represent revisions rather than separate requests, retries, or inconsistent historical state. Never assign revision identity by `cashier_shift_id` alone and never assume one row per shift without querying and reviewing the data.

## 4. Proposed additive target model

The following is the minimal target proposal for S1-05 review. Every table and field name in §4 is **PROPOSED**, not existing schema. No migration is authorized by this document.

### 4.1 Revision snapshots

Add a child table such as `cashier_shift_handover_revisions` with:

- **PROPOSED** UUID primary key;
- **PROPOSED** FK `cashier_shift_handover_id` to the existing handover row UUID (stable request identity);
- **PROPOSED** positive `revision_number`;
- **PROPOSED** canonical `snapshot` JSON containing the submitted report state and references to its sales-breakdown, variance/allocation, recipient, and evidence data;
- **PROPOSED** `payload_sha256` over the canonical snapshot for comparison/integrity checks;
- **PROPOSED** actor ID/type and recorded timestamp;
- **PROPOSED** revision reason/state metadata needed to distinguish submitted, rejected, and resubmitted versions.

Require **PROPOSED** `UNIQUE (cashier_shift_handover_id, revision_number)` and a **PROPOSED** index supporting parent/latest-revision reads. Writers must lock the parent/request row, require the expected current revision, allocate the next number in the same transaction, and reject stale updates. A changed payload is a new revision, never an overwrite. The payload hash is not a substitute for authorization or an immutable-write policy.

The immutable snapshot must retain, directly or via stable referenced child IDs, the submitted financial totals; channel breakdown; counted/expected/variance values; provisional and confirmed shortage allocations; request amount and recipient; evidence/attachment references; rejection reason; actor identity/type; submission timestamp; and the decision state needed to interpret that version. Receipt-confirmation amount, confirming recipient, confirmation timestamp, receipt reference, and confirmation evidence belong to the separate receipt record (§4.2), not a mutable prior snapshot. Preserve uploaded file objects and their references; a DB row containing a path alone does not preserve a file if another workflow deletes it.

Identity/selection rules: immutable revision identity is `(cashier_shift_handover_id, revision_number)` plus its UUID; a correction reuses its request parent UUID and appends a child revision rather than creating another parent. Current revision for an explicitly selected request is the highest committed revision number, read under a parent lock for writes. A shift lookup returns no request when there are no parents, returns the one parent only when exactly one exists, and fails closed for multiple parents pending reconciliation; it must never pick unordered `first()`. A new revision supersedes the previous report snapshot but does not erase it. A decision carrying a revision other than the current revision is stale and must return a conflict without writing. The final HTTP status/envelope is for S1-05. A receipt is immutable and remains linked to the revision it confirms even after a correction; no new revision may rewrite or delete its amount, ledger movement, or evidence.

For a report change, the eventual implementation must atomically append the revision and update any compatibility projection/current pointer plus its database decision record; external file upload and notifications must stay outside the database transaction and be linked by durable evidence/outbox behavior. A receipt confirmation and its durable financial identity must be committed atomically before asynchronous projections are considered complete. Exact route events and outbox design are not established here and remain S1-05/S1-08/S1-09 work.

Do **not** use `UNIQUE (cashier_shift_id, revision_number)`: the parent request UUID, not the shift ID, is the proposed revision identity. Do not add a `revision_number DEFAULT 1` to the mutable legacy table and claim that it captures history. The application must stop destructive reset paths from deleting prior revision snapshots. Existing legacy child tables may remain as a compatibility projection during an expand phase; their exact current-revision selection belongs in S1-05.

### 4.2 Receipt confirmations

Add a separate append-only record such as **PROPOSED** `cashier_shift_handover_receipts` containing **PROPOSED** UUID identity, request and revision FKs, **PROPOSED** nonnegative integer `amount_halalas`, recipient actor ID/type, confirmed timestamp, optional external `receipt_reference`, and evidence references. One row represents one confirmed receipt event; multiple rows can represent explicitly supported partial receipts. A receipt is immutable after confirmation. Request, requested amount, receipt, and remaining unreceived responsibility are separate values.

The proposed revision/receipt foreign keys must not cascade-delete historical evidence. Use restrictive/no-action delete behavior for the new evidence references and retain the legacy parent/shift records while evidence exists; the current parent FK on `cashier_shift_handovers` cascades from `cashier_shifts`, so deletion behavior must be reviewed as part of the additive migration design. A hard delete of a shift with revision/receipt children must be blocked or handled by a reviewed archival process, not cascade history away.

Use an explicit halala suffix for the proposed integer amount. Convert a legacy SAR request once at the boundary; do not store a float-derived “confirmed” amount or rewrite previous receipts when a request is corrected. The exact partial-receipt rules, cross-revision behavior, and request idempotency identity must be finalized in S1-05. No receipt rows are backfilled from settings, opening float, `approved_at`, `handed_over_at`, or requested `handover_amount`.

### 4.3 Shift-level request uniqueness is conditional

The current code behaves as though it reads one current request per shift in several paths, but the database permits multiples. Before adding any unique `cashier_shift_id` constraint, run the duplicate preflight in §7 and determine with a business/data owner whether every repeated group is invalid duplication or a distinct historical request. If any group is ambiguous, stop: do not delete, merge, renumber, or auto-select rows. A one-current-request-per-shift constraint remains **deferred to S1-05** until this cardinality is approved and the data is reconciled. The per-request revision unique key above is independent of that decision.

### 4.4 Idempotency and financial-operation identity

The existing `asab_idempotency_keys` table has a globally unique `key`, nullable `user_id`, method/path, response/status, and expiry (`Modules/Admin/database/migrations/2026_06_02_000001_create_asab_layer_tables.php:151-164`). `Modules/Admin/app/Http/Middleware/IdempotencyKey.php:16-45` looks up the key and replays an unexpired stored response without comparing caller, tenant, resource, revision, or request-body hash. `asab_operations` has unique `public_id`, but nullable, non-unique `source_module`/`source_id`; a unique public operation ID does not deduplicate a retried request.

Target idempotency semantics must bind the authenticated actor and tenant/company scope, resource/request identity, expected revision, operation kind, and canonical payload hash. A retry with the same key and same hash returns the original result; same key with a different payload is rejected; a lost response can be recovered without repeating the financial effect. Authentication/authorization must still run before any stored response is replayed. The final storage and indexes are deferred to S1-05 because the affected route and operation boundary must be selected first. Do not place an unscoped key on `cashier_shift_handovers`, and do not add `operation_id` until the one-operation-per-request/revision relationship is proven.

Logical financial-operation identity must therefore include the tenant/company, operation kind, source request/revision or receipt identity, actor, and payload hash; a generated `asab_operations.public_id` is a display/record identifier, not that logical deduplication key. The operation cardinality (for example, whether one receipt can generate one or more ledger movements) is not proven by current schema and is explicitly deferred rather than guessed.

## 5. Reader/writer, compatibility, and invariant impact

| Area | Current source behavior / impact | Required compatibility or target handling |
|---|---|---|
| Request creation/edit/rejection | `HandoverService::recordHandover`, `recordHandoverEdit`, rejection, and reset paths create, mutate, or delete rows by shift. | Preserve submitted versions before updating any compatibility projection. Replace `first()`/bulk shift updates with a deterministic request/revision selector. Reset must not remove confirmed receipt or prior revision evidence. |
| Cashier and manager reads | `CashierShift::handover()` is `HasOne`; `BranchManagerShiftController` also loads a handover by explicit UUID. `CashierShiftResource` contains a fallback query by `cashier_shift_id`. | UUID-addressed detail can retain existing envelope. Shift-level summary must have an approved deterministic current-request rule; do not rely on unordered `HasOne` while duplicates are possible. |
| Financial calculation | Legacy Shift models and handover fields use SAR decimals; Admin Shift calculations use integer halalas. Bridge converts legacy SAR to Admin halalas once. | No storage rewrite. Every new amount field names its unit. Opening, requested amount, confirmed receipt, physical count, and remaining responsibility cannot be collapsed into one field. |
| Custody and personal ledgers | Custody/personal-ledger services and event listeners consume existing handover/approval data; route-map and money-contract trace these writers and transaction timing. Some writes occur post-commit or are caught as best-effort. | Receipt confirmation becomes the authority for received-cash custody. Record stable source receipt/revision identity for deduplication only after S1-05 chooses the operation schema. Keep liability allocation/approval separate from custody. |
| Dashboard | Dashboard native Admin fields are integer halalas; existing operation decision hooks concern Admin operations, not legacy handover receipt/revision. | Keep existing fields/aliases; add version/receipt data only after the API blueprint defines exact additive keys and behavior. Do not ask the Dashboard to infer a confirmed receipt from a requested amount. |
| Flutter compatibility reference | Mobile models use legacy numeric SAR request fields and existing handover routes. The reference remains read-only. | Preserve legacy SAR payloads and response keys through an adapter. No Flutter change is proposed. |
| Test fixtures and consumers | Existing focused baseline fixtures/tests exercise current schema/behavior; they do not establish revision/idempotency compatibility. | Update factories to create a request parent plus explicit revision/receipt records when implementation is approved. Add old-payload/new-response compatibility and stale-revision/idempotency tests. These checks are NOT RUN here. |

Fixture compatibility plan for the later implementation: the inspected tests create `CashierShiftHandover` rows directly in `tests/Feature/HandoverLedgerDateTest.php:64` and `tests/Feature/ShiftHandoverVarianceCustodyTest.php:49,105,224`; they will need an explicit initial revision only if they exercise revision-aware readers/writers. Receipt tests must create a separate receipt row and assert it remains after correction. Preserve existing `RefreshDatabase` migration order and legacy factory attributes; do not make all handover fixtures implicitly “received” or add broad seed data. Focused tests will cover one request/initial revision, correction/new snapshot, stale revision conflict, no-change retry, same-key/different-payload rejection, duplicate-row fail-closed behavior, receipt immutability, legacy SAR payload compatibility, and existing custody/ledger effects. These are planned only.

Known source-level read/write inventory (the route/API docs remain the exact route and payload authority):

| Source | Current use | Revision/receipt change impact |
|---|---|---|
| `Modules/Shift/app/Services/HandoverService.php` | Creates requests; selects first row by shift for approval/acceptance; edits/rejects by shift; reset hard-deletes rows; cashier acceptance records custody afterward. | All write paths need parent lock, expected revision, snapshot append, receipt-only confirmation, non-destructive correction, and idempotent financial effects. |
| `Modules/Shift/app/Http/Controllers/ShiftHandoverController.php`; `CashierShiftController.php`; `BranchManagerShiftController.php` | Submit, accept/reject, manager decisions, current shift summaries, daily submit and selected detail by handover UUID. | Request validation/auth stays compatible; shift-level selection must fail closed on ambiguous duplicates; daily submit must check the approved current revision/required approvals. |
| `Modules/Shift/app/Http/Controllers/ShiftEndController.php`; `Modules/Shift/app/Services/ShiftEndService.php` | End-only and end-with-handover write report totals, sales lines/history, handover path and variance. | Snapshot must capture the same transaction's report/channel/allocation state; do not break legacy SAR request shape or transaction/after-commit behavior without S1-05 design. |
| `Modules/Shift/app/Models/CashierShift.php`; `CashierShiftHandover.php`; `ShiftSalesBreakdown.php`; `ShiftVarianceDetail.php`; `CashierShiftHistory.php` | `HasOne` request, mutable request casts/fields, channel/allocation relationships, generic history. | Add explicit revision/receipt relationships; don't use `HasOne` as a uniqueness guarantee; keep old model/API projection during expand. |
| `Modules/Shift/app/Transformers/CashierShiftResource.php`; `ShiftDetailResource.php`; `HandoverSummaryResource.php`; `HandoverDetailResource.php`; `VarianceSummaryResource.php` | Read current handover, amounts, approval/rejection and variance details; one fallback lookup is by shift ID. | Every summary/detail must share the approved current-revision resolver and preserve legacy field names/units. |
| `Modules/Shift/app/Services/BranchManagerShiftService.php`; `ShiftFinancialService.php`; `VarianceCalculationService.php` | Manager/report financial summaries, handover selection, totals and allocation/variance reads/writes. | Select a deterministic revision and snapshot all relevant financial/detail rows; stale writes must not update a superseded report. |
| `Modules/Shift/app/Observers/CashierShiftObserver.php` | Fills opening from configured schedule when opening is null or zero. | A setting/default is not a confirmed receipt; revision/receipt reads must keep unset/configured/confirmed values distinct. |
| `Modules/Custody/app/Services/CashierCustodyService.php`; `PersonalLedgerService.php`; `Modules/Custody/app/Listeners/CreateCustodyLedgerEntriesForVariance.php`; `CreatePersonalLedgerTransactionFromHandover.php`; `Modules/Custody/app/Events/HandoverApproved.php` | Write/read custody and personal-ledger transactions from handover/variance events; some are post-commit or caught best-effort. | Tie any new receipt movement to immutable receipt identity; deduplicate separately from request revision and never reverse/delete a confirmed movement on report correction. |
| `Modules/Admin/app/Listeners/BridgeLegacyCashierShift.php`; `ShiftCloseService.php`; `Modules/Admin/app/Http/Middleware/IdempotencyKey.php` | Convert legacy money once, create Admin shift operation, and replay generic mutation response by idempotency key. | Preserve halala unit; do not assume public operation ID is request idempotency; implement payload/tenant/resource/revision binding only after S1-05. |
| Dashboard `artifacts/mockup-sandbox/src/api/queries/shifts.ts`, `types/company.ts`, `money.ts`; Flutter `shift_management_remote_data_source.dart`, request/model files listed by `route-map.md` | Dashboard native Admin shift reads/writes integer halalas; Flutter legacy shifts use SAR numeric fields and existing handover route shape. | Preserve old contracts and omitted/null behavior; new revision/receipt keys must be additive and verified on each consumer. No consumer changes are included here. |

Source map: `Modules/Shift/app/Services/HandoverService.php:38-123,188-303,383-416,443-490,541-590,604-697`; `Modules/Shift/app/Models/CashierShift.php:117-140`; `Modules/Shift/app/Transformers/CashierShiftResource.php:454-465`; `Modules/Shift/app/Http/Controllers/BranchManagerShiftController.php:249-329,730-777`; `Modules/Custody/app/Services/CashierCustodyService.php`; `Modules/Custody/app/Services/PersonalLedgerService.php`; `Modules/Custody/app/Listeners/CreateCustodyLedgerEntriesForVariance.php`; `Modules/Custody/app/Listeners/CreatePersonalLedgerTransactionFromHandover.php`. Exact route, request, resource, actor/middleware, and transaction evidence remains in `route-map.md` and `api-contract.md`.

## 6. Business-rule alignment and gap

BR-05/06 require sender responsibility for unreceived cash and keep report submission, receipt confirmation, and final shortage approval independent. BR-07/08 require provisional allocation, cashier confirmation of the complete in-branch allocation before report closure, and no unassigned remainder. BR-09 requires branch-manager final responsibility approval before daily submission; accountant allocation/finalization is AS-IS and does not replace it. BR-10 separates each employee's acceptance/objection from cash receipt and says an objection must not block transfer. BR-11 makes surplus branch property, not employee liability. BR-12 makes a confirmed receipt the basis for incoming opening cash. BR-13/14 preserve a confirmed receipt when a later report is corrected. BR-15/16/17 and BR-24 require correction/rejection to preserve prior values/evidence and confirmed financial facts rather than overwrite or erase them. See `money-contract.md` for the current source trace and AS-IS/TO-BE/GAP authority flow.

**AS-IS:** Legacy handover status and `approved_at`/`handed_over_at` do not provide a separately evidenced, immutable receipt amount. Rejection reset deletes handover, sales-breakdown, and variance-detail rows; generic shift history is not a full snapshot. The Admin operation pipeline has operation IDs but permits null source identity and current middleware does not bind the idempotency key to payload/resource. These are source observations, not claims that historical duplicates or missing data exist.

**TO-BE:** Each submitted report revision is preserved. A correction produces a new revision. A confirmed receipt is a distinct immutable fact tied to the receiving actor and the relevant request/revision. Unreceived remainder stays with the sender until receipt confirmation. A stale revision cannot overwrite current state. Branch manager liability approval, employee response, accountant reporting, and cash custody remain separate authorities.

**GAP:** Current row selection is nondeterministic if multiple handover rows exist for one shift; existing schema cannot express report revisions or confirmed receipt identity; the reset path destroys operational details; current idempotency replay does not detect same-key/different-payload; no database evidence was queried in this documentation task. These gaps require the S1-05 contract and later implementation/testing.

## 7. Migration and data preflight (proposed; NOT RUN)

Run only on an isolated, backed-up target after S1-05 review. These are proposed read-only queries/checks, not executed commands. They must be adapted only if the verified target schema differs; do not silently substitute a production connection.

### 7.1 Identity, duplicate, and state preflight

```sql
-- Verify target identity before every read/write phase.
SELECT @@hostname, @@port, DATABASE(), CURRENT_USER(), VERSION();

-- Rows that prevent assuming one request per shift. Review every returned group and row.
SELECT cashier_shift_id, COUNT(*) AS handover_rows
FROM cashier_shift_handovers
GROUP BY cashier_shift_id
HAVING COUNT(*) > 1;

-- Candidate legacy key for the rejected (cashier_shift_id, revision=1) design.
SELECT cashier_shift_id, 1 AS candidate_revision_number, COUNT(*) AS candidate_rows
FROM cashier_shift_handovers
GROUP BY cashier_shift_id
HAVING COUNT(*) > 1;

-- Candidate key for the proposed (parent handover UUID, revision=1) design.
-- Existing id is a primary key; this is a reproducible mapping check, not proof
-- that history exists or that shift-level parent cardinality is valid.
SELECT id AS proposed_parent_id, 1 AS candidate_revision_number, COUNT(*) AS candidate_rows
FROM cashier_shift_handovers
GROUP BY id
HAVING COUNT(*) > 1;

SELECT h.id, h.cashier_shift_id, h.status, h.handover_amount, h.variance_amount,
       h.handover_to_id, h.handover_to_type, h.approved_by_id, h.approved_by_type,
       h.approved_at, h.handed_over_at, h.created_at, h.updated_at
FROM cashier_shift_handovers AS h
JOIN (
    SELECT cashier_shift_id
    FROM cashier_shift_handovers
    GROUP BY cashier_shift_id
    HAVING COUNT(*) > 1
) AS d ON d.cashier_shift_id = h.cashier_shift_id
ORDER BY h.cashier_shift_id, h.created_at, h.id;

-- Status/timestamp contradictions and candidate receipt states; candidates are not proof of receipt.
SELECT id, cashier_shift_id, status, handover_amount, approved_by_id, approved_at, handed_over_at
FROM cashier_shift_handovers
WHERE (status = 'approved' AND approved_at IS NULL)
   OR (status <> 'approved' AND approved_at IS NOT NULL)
   OR (handed_over_at IS NOT NULL AND status NOT IN ('approved'));

SELECT id, cashier_shift_id, handover_amount, status, approved_by_id, approved_at,
       handed_over_at, handover_to_id, handover_to_type
FROM cashier_shift_handovers
WHERE approved_at IS NOT NULL OR handed_over_at IS NOT NULL OR status = 'approved'
ORDER BY cashier_shift_id, created_at, id;
```

Do not backfill the second query's candidates as confirmed receipts. Review underlying event/ledger evidence with the business owner; if confirmation cannot be proven, preserve the legacy state as unknown rather than inventing a receipt.

The prior proposal's `UNIQUE (cashier_shift_id, revision_number)` with revision `1` for every existing row is specifically rejected. The duplicate-shift query above is the direct preflight for that invalid assumption. The proposed child uniqueness `(cashier_shift_handover_id, revision_number)` uses a distinct existing UUID per parent; verify candidate migration identity by counting unique parent IDs and reviewing all duplicate shift groups before generating revision 1 snapshots.

### 7.2 Existing idempotency/operation candidates

```sql
SELECT `key`, COUNT(*) AS rows_for_key
FROM asab_idempotency_keys
GROUP BY `key`
HAVING COUNT(*) > 1;

SELECT COUNT(*) AS null_or_empty_keys
FROM asab_idempotency_keys
WHERE `key` IS NULL OR `key` = '';

SELECT id, user_id, method, path, expires_at
FROM asab_idempotency_keys
WHERE `key` IS NULL OR `key` = '';

SELECT company_id, module_key, source_module, source_id, COUNT(*) AS operation_rows
FROM asab_operations
WHERE source_id IS NOT NULL
GROUP BY company_id, module_key, source_module, source_id
HAVING COUNT(*) > 1;
```

`asab_idempotency_keys.key` is declared NOT NULL and UNIQUE in the current migration, so the null/duplicate checks validate schema/data assumptions and the empty-string query catches malformed values; they do not establish tenant/resource/payload scope. These queries describe present schema limitations; an empty result does not prove that middleware keys are scoped to payloads or that operation retries are safe. Before adding constraints, S1-05 must define the operation kinds and whether one or multiple financial operations can arise from a request revision.

### 7.3 Before/after preservation evidence

Capture exact row counts before schema/backfill and repeat after each phase. Example count inventory (run each SELECT and record the target DB identity with it):

```sql
SELECT 'cashier_shifts' AS table_name, COUNT(*) AS row_count FROM cashier_shifts
UNION ALL SELECT 'cashier_shift_handovers', COUNT(*) FROM cashier_shift_handovers
UNION ALL SELECT 'shift_sales_breakdown', COUNT(*) FROM shift_sales_breakdown
UNION ALL SELECT 'shift_variance_details', COUNT(*) FROM shift_variance_details
UNION ALL SELECT 'cashier_shift_history', COUNT(*) FROM cashier_shift_history
UNION ALL SELECT 'cashier_custody_transactions', COUNT(*) FROM cashier_custody_transactions
UNION ALL SELECT 'personal_ledger_transactions', COUNT(*) FROM personal_ledger_transactions
UNION ALL SELECT 'asab_operations', COUNT(*) FROM asab_operations
UNION ALL SELECT 'asab_idempotency_keys', COUNT(*) FROM asab_idempotency_keys;

-- Legacy SAR fields: null/min/max/sum in native decimal SAR, before and after.
SELECT COUNT(*) AS rows_total,
       SUM(total_sales IS NULL) AS null_total_sales,
       MIN(total_sales) AS min_total_sales, MAX(total_sales) AS max_total_sales,
       SUM(total_sales) AS sum_total_sales_sar,
       SUM(net_sales IS NULL) AS null_net_sales,
       MIN(net_sales) AS min_net_sales, MAX(net_sales) AS max_net_sales,
       SUM(net_sales) AS sum_net_sales_sar,
       SUM(vat_amount IS NULL) AS null_vat,
       MIN(vat_amount) AS min_vat, MAX(vat_amount) AS max_vat,
       SUM(vat_amount) AS sum_vat_sar,
       SUM(cash_collected IS NULL) AS null_cash_collected,
       MIN(cash_collected) AS min_cash_collected, MAX(cash_collected) AS max_cash_collected,
       SUM(cash_collected) AS sum_cash_collected_sar,
       SUM(card_payments IS NULL) AS null_card_payments,
       MIN(card_payments) AS min_card_payments, MAX(card_payments) AS max_card_payments,
       SUM(card_payments) AS sum_card_payments_sar,
       MIN(closing_balance) AS min_closing_balance,
       MAX(closing_balance) AS max_closing_balance,
       SUM(closing_balance) AS sum_closing_balance_sar,
       MIN(opening_balance) AS min_opening_balance,
       MAX(opening_balance) AS max_opening_balance,
       SUM(opening_balance) AS sum_opening_balance_sar,
       MIN(expected_balance) AS min_expected_balance,
       MAX(expected_balance) AS max_expected_balance,
       SUM(expected_balance) AS sum_expected_balance_sar,
       MIN(variance) AS min_variance_sar, MAX(variance) AS max_variance_sar,
       SUM(variance) AS sum_variance_sar
FROM cashier_shifts;

SELECT COUNT(*) AS rows_total,
       SUM(handover_amount IS NULL) AS null_requested_amount,
       MIN(handover_amount) AS min_requested_sar, MAX(handover_amount) AS max_requested_sar,
       SUM(handover_amount) AS sum_requested_sar,
       SUM(variance_amount IS NULL) AS null_variance,
       MIN(variance_amount) AS min_variance_sar, MAX(variance_amount) AS max_variance_sar,
       SUM(variance_amount) AS sum_handover_variance_sar
FROM cashier_shift_handovers;

SELECT COUNT(*) AS rows_total, SUM(amount IS NULL) AS null_amount,
       MIN(amount) AS min_line_sar, MAX(amount) AS max_line_sar,
       SUM(amount) AS sum_channel_lines_sar
FROM shift_sales_breakdown;

SELECT COUNT(*) AS rows_total,
       SUM(variance_amount IS NULL) AS null_variance,
       MIN(variance_amount) AS min_variance_sar, MAX(variance_amount) AS max_variance_sar,
       SUM(variance_amount) AS sum_variance_sar,
       SUM(assigned_amount IS NULL) AS null_assigned,
       MIN(assigned_amount) AS min_assigned_sar, MAX(assigned_amount) AS max_assigned_sar,
       SUM(assigned_amount) AS sum_assigned_sar
FROM shift_variance_details;

-- Admin halala values are reported separately; generic operation.amount must
-- be grouped by module/source because its unit is not globally established.
SELECT COUNT(*) AS rows_total, SUM(sales_amount IS NULL) AS null_sales,
       MIN(sales_amount) AS min_sales_halalas, MAX(sales_amount) AS max_sales_halalas,
       SUM(sales_amount) AS sum_sales_halalas,
       SUM(cash_expected IS NULL) AS null_expected,
       MIN(cash_expected) AS min_expected_halalas, MAX(cash_expected) AS max_expected_halalas,
       SUM(cash_expected) AS sum_expected_halalas,
       SUM(cash_actual IS NULL) AS null_actual,
       MIN(cash_actual) AS min_actual_halalas, MAX(cash_actual) AS max_actual_halalas,
       SUM(cash_actual) AS sum_actual_halalas,
       SUM(variance IS NULL) AS null_variance,
       MIN(variance) AS min_variance_halalas, MAX(variance) AS max_variance_halalas,
       SUM(opening_float IS NULL) AS null_opening,
       MIN(opening_float) AS min_opening_halalas, MAX(opening_float) AS max_opening_halalas,
       SUM(opening_float) AS sum_opening_halalas,
       SUM(variance) AS sum_variance_halalas
FROM asab_shifts;

SELECT module_key, source_module, COUNT(*) AS operations,
       MIN(amount) AS min_native_amount, MAX(amount) AS max_native_amount,
       SUM(amount) AS sum_native_amount
FROM asab_operations
GROUP BY module_key, source_module;
```

The two custody ledgers are legacy SAR decimals; record native totals and preserve soft-deleted rows separately:

```sql
SELECT COUNT(*) AS rows_all, SUM(deleted_at IS NULL) AS active_rows,
       SUM(deleted_at IS NOT NULL) AS soft_deleted_rows,
       SUM(amount IS NULL) AS null_amount, MIN(amount) AS min_amount_sar,
       MAX(amount) AS max_amount_sar, SUM(amount) AS sum_amount_sar
FROM cashier_custody_transactions;

SELECT COUNT(*) AS rows_all, SUM(deleted_at IS NULL) AS active_rows,
       SUM(deleted_at IS NOT NULL) AS soft_deleted_rows,
       SUM(amount IS NULL) AS null_amount, MIN(amount) AS min_amount_sar,
       MAX(amount) AS max_amount_sar, SUM(amount) AS sum_amount_sar
FROM personal_ledger_transactions;
```

For `shift_variance_details`, custody, personal-ledger, and history amounts, capture row count, null count, minimum, maximum, and sums by their verified schema meaning; do not infer a unit from a generic column name. Capture status/tenant/branch counts. Record table data/index size and existing indexes from `information_schema.tables`/`statistics` to estimate rebuild/metadata-lock exposure before proposing a unique index or FK on populated tables. MySQL 8.4 DDL algorithm/lock behavior depends on the exact alteration and table/index shape; no zero-downtime or online-lock guarantee is made here. Any unexpected count, null, amount total, or tenant distribution change is a stop condition.

Verify existing FK orphans and future candidate references before altering constraints:

```sql
SELECT COUNT(*) AS orphan_handovers
FROM cashier_shift_handovers h
LEFT JOIN cashier_shifts s ON s.id = h.cashier_shift_id
WHERE s.id IS NULL;

SELECT COUNT(*) AS orphan_sales_lines
FROM shift_sales_breakdown b
LEFT JOIN cashier_shifts s ON s.id = b.cashier_shift_id
WHERE s.id IS NULL;

SELECT COUNT(*) AS orphan_variance_details
FROM shift_variance_details v
LEFT JOIN cashier_shifts s ON s.id = v.cashier_shift_id
WHERE s.id IS NULL;

-- These related_handover_id columns are UUID references but are not FKs in
-- the inspected legacy table definitions.
SELECT COUNT(*) AS orphan_custody_handover_refs
FROM cashier_custody_transactions c
LEFT JOIN cashier_shift_handovers h ON h.id = c.related_handover_id
WHERE c.related_handover_id IS NOT NULL AND h.id IS NULL;

SELECT COUNT(*) AS orphan_personal_ledger_handover_refs
FROM personal_ledger_transactions p
LEFT JOIN cashier_shift_handovers h ON h.id = p.related_handover_id
WHERE p.related_handover_id IS NOT NULL AND h.id IS NULL;
```

After migration/backfill, repeat all count/null/min/max/sum queries; verify duplicate counts for `(cashier_shift_id)` and `(parent UUID, revision_number)`; verify orphan counts for every new FK; verify receipt row count and confirmed-total sums separately; and test that shift-to-current-request returns exactly zero/one or an explicit ambiguity error, and request-to-current-revision returns the highest committed number. Any unapproved source count/totals change blocks rollout.

The first three snapshot imports, if approved, must be count-reconciled against parent rows and explicitly list excluded/ambiguous parents. Preserve per-status and per-tenant counts and report every candidate confirmed historical row separately. Record the hash-manifest of reconciliation exports. A summary total alone is insufficient if row-level identity changed.

After an approved revision backfill, compare the number of source handover parents with the number of imported initial snapshots and list every excluded/ambiguous parent by UUID with a disposition. Compare canonical snapshot amounts/recipient/status with source row values. Confirm no historical receipt rows were fabricated. Verify foreign keys, unique keys, nullability, tenant/branch associations, and representative legacy API reads. Only forward, newly confirmed receipts should populate the receipt table.

### 7.4 Conditional backfill and rollback

No confirmed-receipt backfill is allowed. If S1-05 confirms a current-state snapshot import is needed, it must be a separate, resumable, idempotent operation: deterministic parent UUID ordering; bounded chunks; checkpointed last ID; unique `(parent_id, revision_number)` prevents duplicate restart; transaction per chunk; dry-run counts; and a reconciliation report. Do not convert ambiguous duplicate shift rows automatically. Unknown historical unit rows are quarantined for human review rather than guessed.

Test additive DDL and any backfill on a fresh disposable clone of the approved local baseline first. Preserve a cold pre-change checkpoint and restore only to a separate empty recovery directory/schema. A schema `down()` can remove the new empty structures only before new revision/receipt evidence is written; deleting a populated receipt/revision table is data loss, so rollback after writes is **not lossless or authorized**. After any new financial evidence exists, recover by a reviewed forward correction or isolated restore from a verified checkpoint, never by dropping the evidence tables. Do not edit applied migrations.

## 8. Verification and acceptance status

| Check | Status | Evidence |
|---|---|---|
| Existing legacy/Admin monetary storage units and bridge conversion | **Source-verified** | Migration/model/service/bridge references in §2; corrects R01. No live SQL was run for S1-03. |
| Handover identity, uniqueness, row selection and destructive writer paths | **Source-verified** | Migration, `HasOne`, `first()`, shift-wide update/delete references in §3; data multiplicity remains **UNKNOWN** without target DB preflight. |
| Idempotency/operation key behavior | **Source-verified** | Middleware and schema references in §4.4; no replay/race test was run. |
| Revision/receipt schema proposal and rollback limitations | **Design proposal** | §4 and §7; no migration authored or executed. |
| Migration/backfill fixture compatibility | **NOT RUN** | No S1-03 migration or fixture change was made. Requires S1-05-reviewed implementation and disposable DB. |
| S1-02 round-trip, stale revision, receipt immutability, idempotency, legacy Dashboard/mobile compatibility | **NOT RUN** | Future acceptance coverage; do not infer from S1-01's historical 41-test/144-assertion baseline. |

### Audit regression status

| Finding | Status | Correction |
|---|---|---|
| R01 — second SAR/halala conversion | **RESOLVED in this ADR** | Admin storage and close service already use halalas; bridge converts legacy SAR once. ADR explicitly prohibits the prior extra conversion and global `/100`. |
| R05 — revision identity, readers/writers, duplicates | **RESOLVED in this ADR** | Uses the handover UUID as a candidate request identity, rejects shift-only revision keys, maps current row readers/writers, and makes duplicate/cardinality preflight a stop gate. Shift-level uniqueness is explicitly deferred pending evidence/approval. |
| R07 — unsupported test/migration claims | **RESOLVED in this ADR** | S1-03 migration/backfill/acceptance tests are marked NOT RUN; S1-01 baseline evidence is not claimed as proof of future behavior. |

**Task status: S1-03 — Ready for review; not Accepted.** The deliverable is a corrected source-based schema/compatibility ADR with explicit conditional decisions and no silent data assumptions. Review of the proposed request cardinality, revision/receipt schema, and S1-05 API contract remains required before implementation. S1-04 is not started by this task.

### R07 evidence categories

1. **Existing executed baseline:** S1-01's historical six-file test run was 41 tests / 144 assertions / 0 failures / 0 errors. It is not a revision, receipt, idempotency, or S1-03 migration test.
2. **Static source verification:** this ADR checked current migration/model/service/middleware and referenced route, resource, Dashboard, Flutter-compatibility, and S1-02 contract sources. Static inspection does not establish current production-like row multiplicity or runtime replay behavior.
3. **Planned migration preflight:** the target identity, duplicate, candidate confirmed state, null/value, aggregate, orphan, FK/index, and DDL-lock checks in §7 are **NOT RUN**.
4. **Planned application tests:** fixture compatibility, snapshot immutability, request/receipt separation, stale revision handling, idempotent retry, and legacy client compatibility are **NOT RUN**.
5. **NOT RUN:** no S1-03 database query, schema migration, backfill, seed, fixture, backend test, Dashboard test, or Flutter test was executed. No future migration-success or acceptance guarantee is claimed.

## 9. Principal source references

- Normative scope: `project-docs/Assab-ERP-Sprint-01-Agent-Implementation-Plan.md:158-167`.
- Business rules: `project-docs/Assab-ERP-Cash-Cycle-Business-Rules-v2.0-EN.md:102-200,274-280` (BR-05–17, BR-24).
- Current monetary source: `Modules/Shift/database/migrations/2025_12_01_133846_create_cashier_shift_handovers_table.php:11-54`; `Modules/Shift/app/Models/CashierShift.php:51-63`; `Modules/Admin/database/migrations/2026_06_05_000001_create_asab_ops_detail_tables.php:45-64`; `Modules/Admin/database/migrations/2026_07_12_000001_add_shift_cashier_and_type.php:18-25`; `Modules/Admin/app/Listeners/BridgeLegacyCashierShift.php:80-98,152-155`; `Modules/Admin/app/Services/ShiftCloseService.php:69-103`.
- Handover identity/writes: `Modules/Shift/app/Models/CashierShift.php:117-140`; `Modules/Shift/app/Services/HandoverService.php:38-123,188-235,383-416,443-490,541-590,604-697`.
- Idempotency/operation schema: `Modules/Admin/database/migrations/2026_06_02_000001_create_asab_layer_tables.php:151-195`; `Modules/Admin/app/Http/Middleware/IdempotencyKey.php:16-45`.
- Existing consumer and transaction map: `docs/sprint-01/route-map.md`; API compatibility: `docs/sprint-01/api-contract.md`; current money and authority contract: `docs/sprint-01/money-contract.md`.
- Audit evidence revalidated: `project-docs/Assab-Backend-Commit-Audit-2026-10-07-1.md:27-157`.

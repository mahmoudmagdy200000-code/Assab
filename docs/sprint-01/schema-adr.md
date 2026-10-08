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
| Report revision identity and history retention | **ADDITIVE MIGRATION REQUIRED** | `cashier_shift_history` is a generic action/old/new text log, not a complete revision snapshot. Report identity belongs to a stable report/shift aggregate, independent of whether any handover request exists. Current rejection reset hard-deletes handover and report-detail rows. A new append-only report revision record is needed; schema alone cannot enforce immutability, so append-only service behavior and tests are also required. |
| Handover request identity and report association | **ADDITIVE MIGRATION REQUIRED** | Existing handover rows are mutable requests with stable UUIDs but no report-revision identity. Proposed request-to-revision FK gives each request one immutable source revision; each revision may own zero, one, or multiple requests. No shift-level one-request uniqueness is proposed. Duplicate/cardinality preflight remains mandatory because current `hasOne` readers may hide multiple historical rows. |
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

`CashierShift::handover()` is a `HasOne` relation (`Modules/Shift/app/Models/CashierShift.php:117-124`), but that relationship does not constrain the database or order multiple matching rows. Current source has no report-revision model/table or relationship; this `HasOne` is only the legacy shift-to-request accessor, not report revision ownership. `HandoverService::recordHandover()` inserts a row (`Modules/Shift/app/Services/HandoverService.php:38-123`); approval and cashier acceptance use `where(cashier_shift_id)->first()` (`:188-235,604-620`); rejection/edit paths update rows by shift (`:443-490,541-590`); and the rejection reset deletes all matching handover rows (`:383-416`). `CashierShiftResource` also has a direct fallback query by shift. These are reader/writer impacts, not proof that duplicate rows currently exist.

The existing handover UUID is the source-backed stable request identity; it is not the report revision identity. It is not proven that multiple rows for one shift represent revisions rather than separate requests, retries, or inconsistent historical state. Never assign report revision identity by `cashier_shift_id` or handover request alone, and never assume one request per shift without querying and reviewing the data.

## 4. Proposed additive target model

The following is the minimal target proposal for S1-05 review. Every table and field name in §4 is **PROPOSED**, not existing schema. No migration is authorized by this document.

### 4.1 Stable report aggregate and revision snapshots

Report history must exist independently of handover requests. Add a stable report aggregate keyed by the source report owner, with identity **PROPOSED** as `(source_kind, source_shift_id)` (or an equivalent reviewed FK-safe representation): legacy cashier reports use `cashier_shifts.id`; a native Admin cashier-shift report uses `asab_shifts.id`. Where `asab_shifts.legacy_shift_id` identifies a mirrored legacy report, the mirror must resolve to the same legacy report aggregate rather than create a second report identity. Native Admin shifts without a legacy source use their own Admin shift ID. The current legacy `ShiftEndService::endShiftOnly()` completes and persists report totals/channel rows without creating a handover (`Modules/Shift/app/Services/ShiftEndService.php:17-57`). Native Admin `ShiftCloseService::close()` closes an `asab_shifts` row into the review pipeline and creates an Admin operation, not a legacy handover (`Modules/Admin/app/Services/ShiftCloseService.php:44-103`). Neither path may require a fabricated recipient or amount to obtain report identity. Final aggregate table/FK shape and treatment of any additional report types remain S1-05 blueprint decisions.

Add **PROPOSED** `shift_report_revisions` owned by that stable report aggregate, with:

- **PROPOSED** UUID primary key and FK to the stable report aggregate;
- **PROPOSED** positive `revision_number`, unique within the aggregate;
- **PROPOSED** canonical `snapshot` JSON for submitted report values, channel breakdown, count/expected/variance, allocation state, and evidence references;
- **PROPOSED** `payload_sha256`, actor ID/type, recorded timestamp, and reason/state metadata.

Require **PROPOSED** `UNIQUE (report_aggregate_id, revision_number)` and an index for aggregate/latest-revision reads. Before first submission the aggregate has revision `0` and no report-revision UUID; first submission requires `expectedRevision: 0` and commits revision `1`. A correction/resubmission requires the current positive number and appends `N+1`. Only submission and report correction advance the report revision; request, rejection, receipt, allocation, employee response, manager approval and daily submission bind to the current revision but do not advance it. Writers lock the aggregate, compare the expected current revision, append the next revision atomically with the compatibility projection/decision, and reject stale new intents. Current revision is the aggregate pointer to the highest committed revision, never an unordered request lookup. A report lookup remains valid with zero handover requests. Preserve uploaded file objects as well as their references.

Handover requests are separate records with stable request UUIDs. **Choose one-to-many, not the earlier proposed many-to-many association:** each request is created against exactly one source report-revision UUID; one report revision may have zero, one, or multiple requests over time. A request after an end-only report links to the then-current report revision. A correction never rebinds an older request or confirmed receipt. If corrected custody intent is needed, create a new request UUID against the new revision; reject/supersede the old unconfirmed request while retaining its reason, actor, time and attachments. Already confirmed requests and receipts remain historical and immutable. This choice covers end-only, native Admin close and later handovers without a fabricated request; no inspected source or business scenario requires many-to-many linking.

Revision identity is `(report_aggregate_id, revision_number)` plus revision UUID, not `(cashier_shift_handover_id, revision_number)` and not shift-level handover cardinality. A *new* decision against a non-current revision is stale and must conflict without writing; an authorized completed same-key/same-payload replay returns its saved result first. A shift-to-request lookup may return zero, one, or multiple requests and must use an explicit request identity or fail closed when a route requires one; it must not choose unordered `first()`.

Receipt confirmations remain immutable and retain their stable receipt UUID, request UUID, and original report-revision UUID after a correction. A correction must not rewrite/delete an existing request, receipt, receipt amount, ledger identity, or evidence; a new receipt is a new event on a new request. A report revision and request/receipt identities are separate facts. The proposed revision snapshot retains the request IDs known at submission; the separate receipt record (§4.2) owns confirmed amount, recipient, time, reference and evidence. For a report change, append the report revision and update any compatibility projection/current pointer plus its decision record atomically. Receipt confirmation and durable financial identity must be committed atomically before asynchronous projections complete. File upload, notifications, and outbox details remain S1-05/S1-08/S1-09 work.

Do **not** make a report revision a child of a handover request, require a handover before report submission/close, or create a fake handover with an invented recipient/amount. Do not use `UNIQUE (cashier_shift_id, revision_number)` or `UNIQUE (cashier_shift_handover_id, revision_number)` as report revision identity. Do not add a revision number to mutable legacy tables and claim it captures history. Existing legacy report/detail rows may remain compatibility projections; their exact current-revision selection belongs in S1-05.

### 4.2 Receipt confirmations

Add a separate append-only record such as **PROPOSED** `cashier_shift_handover_receipts` containing **PROPOSED** UUID identity, unique stable request UUID and original report-revision UUID FKs, **PROPOSED** nonnegative integer `amount_halalas`, recipient actor ID/type, stable receiving shift/custody intent ID, confirmed timestamp, optional external `receipt_reference`, and evidence references. **At most one confirmed receipt per corrected request**; multiple intentional partial transfers use multiple explicit requests, each for its actual intended amount, not multiple smaller confirmations against one unchanged request. The receipt amount must equal its current valid request amount. A count mismatch requires rejection, correction and a new request UUID before confirmation (BR-13/14). A zero request may yield a zero receipt with immutable recipient/destination identity but no cash ledger movement. A receipt is immutable after confirmation and keeps the revision it confirms when the report changes. Request, receipt and remaining unreceived responsibility are separate values.

The proposed revision/receipt foreign keys must not cascade-delete historical evidence. Use restrictive/no-action delete behavior for the new evidence references and retain the legacy parent/shift records while evidence exists; the current parent FK on `cashier_shift_handovers` cascades from `cashier_shifts`, so deletion behavior must be reviewed as part of the additive migration design. A hard delete of a shift with revision/receipt children must be blocked or handled by a reviewed archival process, not cascade history away.

Use an explicit halala suffix for the proposed integer amount. Convert a legacy SAR request once at the boundary; do not store a float-derived “confirmed” amount or rewrite previous receipts when a request is corrected. Receipt UUID identifies the immutable record; request replay key identifies a client's attempt; **PROPOSED FOR MAHMOUD REVIEW** permanent effect uniqueness is `(tenant/company, branch, request UUID, effect type, destination custody/shift identity)`. For pre-open receipt, the reserved receiving-intent UUID is that permanent destination identity; binding it to the created shift never changes/reposts the effect key. One request has at most one confirmation, so a retry cannot mint another effect after replay-cache expiry; another intentional partial transfer uses a new request UUID. Distinct sender-out, recipient-in/opening and audit effect types share one transaction with the receipt. No receipt rows are backfilled from settings, opening float, `approved_at`, `handed_over_at`, or requested `handover_amount`.

### 4.3 Shift-level request uniqueness is conditional

The current code behaves as though it reads one current request per shift in several paths, but the database permits multiples. Before adding any shift-level uniqueness, run the duplicate preflight in §7 and determine whether repeated rows are duplicates or distinct historical requests. If ambiguous, stop: do not delete, merge, renumber or auto-select rows. **No shift-level one-request uniqueness is proposed**: a report can have multiple historical and intentional partial-transfer requests. Proposed uniqueness is one receipt per request and one report revision number per aggregate. Readers requiring a specific request must use its UUID.

### 4.4 Idempotency and financial-operation identity

The existing `asab_idempotency_keys` table has a globally unique `key`, nullable `user_id`, method/path, response/status, and expiry (`Modules/Admin/database/migrations/2026_06_02_000001_create_asab_layer_tables.php:151-164`). `Modules/Admin/app/Http/Middleware/IdempotencyKey.php:16-45` looks up the key and replays an unexpired stored response without comparing caller, tenant, resource, revision, or request-body hash. `asab_operations` has unique `public_id`, but nullable, non-unique `source_module`/`source_id`; a unique public operation ID does not deduplicate a retried request.

Target idempotency semantics must bind the authenticated actor and tenant/company scope, resource/request identity, expected revision, operation kind, and canonical payload hash. A retry with the same key and same hash returns the original result; same key with a different payload is rejected; a lost response can be recovered without repeating the financial effect. Authentication/authorization must still run before any stored response is replayed. The final storage and indexes are deferred to S1-05 because the affected route and operation boundary must be selected first. Do not place an unscoped key on `cashier_shift_handovers`, and do not add `operation_id` until the one-operation-per-request/revision relationship is proven.

Logical request-replay identity includes tenant/company, branch, actor, command, resource, expected revision, key and canonical payload hash. Permanent receipt movement identity is the distinct `(tenant/company, branch, request UUID, effect type, destination custody/shift identity)` tuple in §4.2; it does not expire with the request cache. A generated `asab_operations.public_id` is a display/record identifier, not either deduplication key. Multiple required debit/credit/audit effects for one receipt have distinct effect types but commit together. This is a design proposal awaiting Mahmoud review, not an existing uniqueness constraint.

## 5. Reader/writer, compatibility, and invariant impact

| Area | Current source behavior / impact | Required compatibility or target handling |
|---|---|---|
| Request creation/edit/rejection | `HandoverService::recordHandover`, `recordHandoverEdit`, rejection, and reset paths create, mutate, or delete rows by shift. | Preserve submitted versions before updating any compatibility projection. Replace `first()`/bulk shift updates with a deterministic request/revision selector. Reset must not remove confirmed receipt or prior revision evidence. |
| Cashier and manager reads | `CashierShift::handover()` is `HasOne`; `BranchManagerShiftController` also loads a handover by explicit UUID. `CashierShiftResource` contains a fallback query by `cashier_shift_id`. | UUID-addressed detail can retain existing envelope. Shift-level summary must have an approved deterministic current-request rule; do not rely on unordered `HasOne` while duplicates are possible. |
| Financial calculation | Legacy Shift models and handover fields use SAR decimals; Admin Shift calculations use integer halalas. Bridge converts legacy SAR to Admin halalas once. | No storage rewrite. Every new amount field names its unit. Opening, requested amount, confirmed receipt, physical count, and remaining responsibility cannot be collapsed into one field. |
| Custody and personal ledgers | Custody/personal-ledger services and event listeners consume existing handover/approval data; route-map and money-contract trace these writers and transaction timing. Some writes occur post-commit or are caught as best-effort. | Receipt confirmation becomes the authority for received-cash custody. Record stable source receipt/revision identity for deduplication only after S1-05 chooses the operation schema. Keep liability allocation/approval separate from custody. |
| Dashboard | Dashboard native Admin fields are integer halalas; existing operation decision hooks concern Admin operations, not legacy handover receipt/revision. | Keep existing fields/aliases; add version/receipt data only after the API blueprint defines exact additive keys and behavior. Do not ask the Dashboard to infer a confirmed receipt from a requested amount. |
| Flutter compatibility reference | Mobile models use legacy numeric SAR request fields and existing handover routes. The reference remains read-only. | Preserve legacy SAR payloads and response keys through an adapter. No Flutter change is proposed. |
| Test fixtures and consumers | Existing focused baseline fixtures/tests exercise current schema/behavior; they do not establish revision/idempotency compatibility. | Add report-only revision fixtures that require no handover; create explicit request links and receipt records only in scenarios that use them. Add old-payload/new-response compatibility and stale-revision/idempotency tests. These checks are NOT RUN here. |

Fixture compatibility plan for later implementation: include **end-only report revision → handover request later → receipt confirmed → report correction → earlier report revision, original request and confirmed receipt remain preserved**. The initial `ShiftEndService::endShiftOnly()` revision has no handover parent; the later request points to that revision, and correction appends a new report revision without rebinding the request/receipt. A changed custody intent creates another request UUID. Also cover native Admin close without a legacy handover. Existing tests create `CashierShiftHandover` rows directly in `tests/Feature/HandoverLedgerDateTest.php:64` and `tests/Feature/ShiftHandoverVarianceCustodyTest.php:49,105,224`; revision-aware fixtures need explicit source revision identity. Receipt tests must assert preservation after correction. Preserve existing `RefreshDatabase` migration order and factory attributes; do not make all handover fixtures implicitly “received” or add broad seed data. Further focused tests will cover stale revision conflict, no-change retry, same-key/different-payload rejection, duplicate request fail-closed behavior, receipt immutability, legacy SAR compatibility, and existing custody/ledger effects. These are planned only.

Known source-level read/write inventory (the route/API docs remain the exact route and payload authority):

| Source | Current use | Revision/receipt change impact |
|---|---|---|
| `Modules/Shift/app/Services/HandoverService.php` | Creates requests; selects first row by shift for approval/acceptance; edits/rejects by shift; reset hard-deletes rows; cashier acceptance records custody afterward. | All write paths need aggregate/request lock, expected revision, request-specific history, receipt-only confirmation, non-destructive correction, and idempotent financial effects. Handover actions do not append report revisions. |
| `Modules/Shift/app/Http/Controllers/ShiftHandoverController.php`; `CashierShiftController.php`; `BranchManagerShiftController.php` | Submit, accept/reject, manager decisions, current shift summaries, daily submit and selected detail by handover UUID. | Request validation/auth stays compatible; shift-level selection must fail closed on ambiguous duplicates; daily submit must check the approved current revision/required approvals. |
| `Modules/Shift/app/Http/Controllers/ShiftEndController.php`; `Modules/Shift/app/Services/ShiftEndService.php` | End-only can write report totals, sales lines/history without any handover; end-with-handover invokes the separate handover path. | End-only must create a report revision under the stable cashier-shift aggregate with zero requests; a later request links by stable IDs. Snapshot the report/channel/allocation state; do not break legacy SAR request shape or transaction/after-commit behavior without S1-05 design. |
| `Modules/Admin/app/Services/ShiftCloseService.php`; `Modules/Admin/app/Models/Shift.php` | Native Admin close updates `asab_shifts` and creates an Admin review operation without creating a legacy handover request. | Preserve a native Admin report revision under its stable Admin shift aggregate; legacy-backed mirror shifts resolve to their legacy aggregate. No handover fabrication or new client behavior is implied. |
| `Modules/Shift/app/Models/CashierShift.php`; `CashierShiftHandover.php`; `ShiftSalesBreakdown.php`; `ShiftVarianceDetail.php`; `CashierShiftHistory.php` | `HasOne` request, mutable request casts/fields, channel/allocation relationships, generic history. | Add explicit revision/receipt relationships; don't use `HasOne` as a uniqueness guarantee; keep old model/API projection during expand. |
| `Modules/Shift/app/Transformers/CashierShiftResource.php`; `ShiftDetailResource.php`; `HandoverSummaryResource.php`; `HandoverDetailResource.php`; `VarianceSummaryResource.php` | Read current handover, amounts, approval/rejection and variance details; one fallback lookup is by shift ID. | Every summary/detail must share the approved current-revision resolver and preserve legacy field names/units. |
| `Modules/Shift/app/Services/BranchManagerShiftService.php`; `ShiftFinancialService.php`; `VarianceCalculationService.php` | Manager/report financial summaries, handover selection, totals and allocation/variance reads/writes. | Select a deterministic revision and snapshot all relevant financial/detail rows; stale writes must not update a superseded report. |
| `Modules/Shift/app/Observers/CashierShiftObserver.php` | Fills opening from configured schedule when opening is null or zero. | A setting/default is not a confirmed receipt; revision/receipt reads must keep unset/configured/confirmed values distinct. |
| `Modules/Custody/app/Services/CashierCustodyService.php`; `PersonalLedgerService.php`; `Modules/Custody/app/Listeners/CreateCustodyLedgerEntriesForVariance.php`; `CreatePersonalLedgerTransactionFromHandover.php`; `Modules/Custody/app/Events/HandoverApproved.php` | Write/read custody and personal-ledger transactions from handover/variance events; some are post-commit or caught best-effort. | Tie any new receipt movement to immutable receipt identity; deduplicate separately from request revision and never reverse/delete a confirmed movement on report correction. |
| `Modules/Admin/app/Listeners/BridgeLegacyCashierShift.php`; `ShiftCloseService.php`; `Modules/Admin/app/Http/Middleware/IdempotencyKey.php` | Convert legacy money once, create Admin shift operation, and replay generic mutation response by idempotency key. | Preserve halala unit; do not assume public operation ID is request idempotency; implement payload/tenant/resource/revision binding only after S1-05. |
| Dashboard `artifacts/mockup-sandbox/src/api/queries/shifts.ts`, `types/company.ts`, `money.ts`; Flutter `shift_management_remote_data_source.dart`, request/model files listed by `route-map.md` | Dashboard native Admin shift reads/writes integer halalas; Flutter legacy shifts use SAR numeric fields and existing handover route shape. | Preserve old contracts and omitted/null behavior; new revision/receipt keys must be additive and verified on each consumer. No consumer changes are included here. |

Source map: `Modules/Shift/app/Services/HandoverService.php:38-123,188-303,383-416,443-490,541-590,604-697`; `Modules/Shift/app/Models/CashierShift.php:117-140`; `Modules/Shift/app/Transformers/CashierShiftResource.php:454-465`; `Modules/Shift/app/Http/Controllers/BranchManagerShiftController.php:249-329,730-777`; `Modules/Custody/app/Services/CashierCustodyService.php`; `Modules/Custody/app/Services/PersonalLedgerService.php`; `Modules/Custody/app/Listeners/CreateCustodyLedgerEntriesForVariance.php`; `Modules/Custody/app/Listeners/CreatePersonalLedgerTransactionFromHandover.php`. Exact route, request, resource, actor/middleware, and transaction evidence remains in `route-map.md` and `api-contract.md`.

## 6. Business-rule alignment and gap

BR-05/06 require sender responsibility for unreceived cash and keep report submission, receipt confirmation, and final shortage approval independent. BR-07/08 require provisional allocation, cashier confirmation of the complete in-branch allocation before report closure, and no unassigned remainder. BR-09 requires branch-manager final responsibility approval before daily submission; accountant allocation/finalization is AS-IS and does not replace it. BR-10 separates each employee's acceptance/objection from cash receipt and says an objection must not block transfer. BR-11 makes surplus branch property, not employee liability. BR-12 makes a confirmed receipt the basis for incoming opening cash. BR-13/14 require reject, recount, correct/resubmit a new exact request, then confirm when recipient count differs from the original request; they forbid confirming the unchanged request for a smaller amount. BR-15/16/17 and BR-24 require correction/rejection to preserve prior values/evidence and confirmed financial facts rather than overwrite or erase them. See `money-contract.md` for the current source trace and AS-IS/TO-BE/GAP authority flow.

**AS-IS:** Legacy handover status and `approved_at`/`handed_over_at` do not provide a separately evidenced, immutable receipt amount. Rejection reset deletes handover, sales-breakdown, and variance-detail rows; generic shift history is not a full snapshot. The Admin operation pipeline has operation IDs but permits null source identity and current middleware does not bind the idempotency key to payload/resource. These are source observations, not claims that historical duplicates or missing data exist.

**TO-BE:** Each submitted report revision is preserved under a stable report/shift aggregate, whether it has zero, one, or multiple handover requests. Each request belongs to exactly the revision that created it. A correction creates a new report revision; an old request/receipt remains on its original revision, while corrected custody intent uses a new request UUID. End-only legacy and native Admin close paths do not fabricate handovers. Unreceived remainder stays with the sender until correctly requested receipt confirmation. A stale new intent cannot overwrite current state. Branch manager liability approval, employee response, accountant reporting, and cash custody remain separate authorities.

**GAP:** Current row selection is nondeterministic if multiple handover rows exist for one shift; existing schema cannot express report revisions or confirmed receipt identity; the reset path destroys operational details; current idempotency replay does not detect same-key/different-payload; no database evidence was queried in this documentation task. These gaps require the S1-05 contract and later implementation/testing.

## 7. Migration and data preflight (proposed; NOT RUN)

Run only on an isolated, backed-up target after S1-05 review. These are proposed read-only queries/checks, not executed commands. They must be adapted only if the verified target schema differs; do not silently substitute a production connection.

### 7.1 Identity, duplicate, and state preflight

```sql
-- Verify target identity before every read/write phase.
SELECT @@hostname, @@port, DATABASE(), CURRENT_USER(), VERSION();

-- Candidate stable report owners. Legacy-backed Admin mirror rows resolve to
-- the legacy cashier-shift key and are not counted as a second report owner.
SELECT 'legacy_cashier_shift' AS source_kind, id AS source_shift_id
FROM cashier_shifts
UNION ALL
SELECT 'native_admin_shift' AS source_kind, id AS source_shift_id
FROM asab_shifts
WHERE legacy_shift_id IS NULL;

-- Candidate duplicate mirror links that require reconciliation before mapping
-- Admin rows to their legacy report aggregate.
SELECT legacy_shift_id, COUNT(*) AS admin_mirrors
FROM asab_shifts
WHERE legacy_shift_id IS NOT NULL
GROUP BY legacy_shift_id
HAVING COUNT(*) > 1;

-- Rows that prevent assuming one request per shift. Review every returned group and row.
SELECT cashier_shift_id, COUNT(*) AS handover_rows
FROM cashier_shift_handovers
GROUP BY cashier_shift_id
HAVING COUNT(*) > 1;

-- Proposed report revision key is aggregate + revision number, never request
-- or shift ID alone. After candidate aggregate mapping, check the proposed key
-- before inserting any imported initial/current snapshots.
-- SELECT report_aggregate_id, revision_number, COUNT(*) AS duplicate_keys
-- FROM shift_report_revisions
-- GROUP BY report_aggregate_id, revision_number
-- HAVING COUNT(*) > 1;

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

Do not treat approved/handed-over status or timestamp query results as confirmed receipts. Review underlying event/ledger evidence with the business owner; if confirmation cannot be proven, preserve the legacy state as unknown rather than inventing a receipt.

The prior proposal's `UNIQUE (cashier_shift_id, revision_number)` and request-parent revision key are rejected as report identity. Reconcile candidate report aggregate ownership across legacy cashier rows and Admin mirrors/native shifts before creating revision 1 snapshots. A revision with no linked handover is valid; report-snapshot counts must include end-only and native Admin close reports, not only handover rows.

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

After migration/backfill, repeat all count/null/min/max/sum queries; verify duplicate source report identities and `(report_aggregate_id, revision_number)`; verify orphan counts for every new FK; verify report revisions with zero linked requests remain readable; verify receipt row count and confirmed-total sums separately; and test that report-to-request returns zero/one/many as modeled while request/revision selection is explicit and deterministic. Any unapproved source count/totals change blocks rollout.

Any approved initial snapshot import must be count-reconciled against its in-scope report aggregates and explicitly list excluded/ambiguous source identities. Preserve per-status and per-tenant counts and report every candidate confirmed historical row separately. Record the hash-manifest of reconciliation exports. A summary total alone is insufficient if row-level identity changed.

After an approved revision backfill, compare the number of in-scope report aggregates (including report-only end-shift and native Admin close reports) with imported initial snapshots and list every excluded/ambiguous source identity with a disposition. Compare canonical snapshot values with the selected source report. Reconcile handover associations separately and confirm no historical receipt rows were fabricated. Verify foreign keys, unique keys, nullability, tenant/branch associations, and representative legacy API reads. Only forward, newly confirmed receipts should populate the receipt table.

### 7.4 Conditional backfill and rollback

No confirmed-receipt backfill is allowed. If S1-05 confirms a current-state snapshot import is needed, it must be a separate, resumable, idempotent operation: deterministic report-aggregate identity ordering; bounded chunks; checkpointed last identity; unique `(report_aggregate_id, revision_number)` prevents duplicate restart; transaction per chunk; dry-run counts; and a reconciliation report. Do not convert ambiguous duplicate shift/request rows automatically. Unknown historical unit rows are quarantined for human review rather than guessed.

Test additive DDL and any backfill on a fresh disposable clone of the approved local baseline first. Preserve a cold pre-change checkpoint and restore only to a separate empty recovery directory/schema. A schema `down()` can remove the new empty structures only before new revision/receipt evidence is written; deleting a populated receipt/revision table is data loss, so rollback after writes is **not lossless or authorized**. After any new financial evidence exists, recover by a reviewed forward correction or isolated restore from a verified checkpoint, never by dropping the evidence tables. Do not edit applied migrations.

## 8. Verification and acceptance status

| Check | Status | Evidence |
|---|---|---|
| Existing legacy/Admin monetary storage units and bridge conversion | **Source-verified** | Migration/model/service/bridge references in §2; corrects R01. No live SQL was run for S1-03. |
| Handover identity, uniqueness, row selection and destructive writer paths | **Source-verified** | Migration, `HasOne`, `first()`, shift-wide update/delete references in §3; data multiplicity remains **UNKNOWN** without target DB preflight. |
| Idempotency/operation key behavior | **Source-verified** | Middleware and schema references in §4.4; no replay/race test was run. |
| Report-only, end-only, and native Admin report revision identity | **Design proposal; statically source-checked** | §4.1 distinguishes the stable report aggregate from zero/one/multiple optional handover associations and covers both source close paths; no migration or runtime test was executed. |
| Revision/receipt schema proposal and rollback limitations | **Design proposal** | §4 and §7; no migration authored or executed. |
| Migration/backfill fixture compatibility | **NOT RUN** | No S1-03 migration or fixture change was made. Requires S1-05-reviewed implementation and disposable DB. |
| S1-02 round-trip, stale revision, receipt immutability, idempotency, legacy Dashboard/mobile compatibility | **NOT RUN** | Future acceptance coverage; do not infer from S1-01's historical 41-test/144-assertion baseline. |

### Audit regression status

| Finding | Status | Correction |
|---|---|---|
| R01 — second SAR/halala conversion | **RESOLVED in this ADR** | Admin storage and close service already use halalas; bridge converts legacy SAR once. ADR explicitly prohibits the prior extra conversion and global `/100`. |
| R05 — revision identity, readers/writers, duplicates | **RESOLVED in this ADR** | Report revision identity belongs to the stable report aggregate and not to a handover request. Every request has one immutable source report revision; report-only and native-close paths work without requests. The ADR maps current readers/writers and keeps duplicate/cardinality preflight as a migration stop gate. No shift-level one-request uniqueness is proposed. |
| R07 — unsupported test/migration claims | **RESOLVED in this ADR** | S1-03 migration/backfill/acceptance tests are marked NOT RUN; S1-01 baseline evidence is not claimed as proof of future behavior. |

**Task status: S1-03 — Ready for review; not Accepted.** The deliverable is a corrected source-based schema/compatibility ADR with explicit conditional decisions and no silent data assumptions. Review of the proposed report aggregate, request cardinality, revision/receipt schema, and S1-05 API contract remains required before implementation. S1-04 is separate from this correction.

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

## Final handoff schema/compatibility — D5 approved, remaining proposals

This section supersedes conflicting technical target values elsewhere in this ADR. **D5 APPROVED — MAHMOUD:** enforce per-column ceilings from verified schema and use integer-halalas arithmetic, two-decimal SAR inputs with excess rejected by HTTP 422, half-up VAT-inclusive net and residual VAT: DECIMAL(12,2) = SAR 9,999,999,999.99; `branch_manager_shifts.handover_amount` DECIMAL(10,2) = SAR 99,999,999.99. Global enforcement cannot exceed the narrowest participating column. No schema or migration changed.

Confirmed opening is the sum of confirmed receipts bound to a shift; an empty set computes zero, while receipt-count evidence distinguishes no receipt from confirmed zero receipts. A configured float is never receipt evidence. Start before confirmation is allowed; a late receipt applies once to its bound shift. Manager opening uses personal sales-cash, not expense custody. Ledger/table mapping remains a technical proposal; `personal_ledger_transactions` is not mandated by Mohamed. D9 applies only to concrete insufficient-balance and permitted post-report receipt edges.

**D4 — DECIDED by Mahmoud (2026-10-08): no rollout flag.** The new required fields (`counted_cash`, `expectedRevision`, `Idempotency-Key`, exact `confirmedAmount`) are enforced on the legacy routes as soon as each is implemented, in every environment. Consequence accepted by Mahmoud: the current AssabAPP sends none of these fields and will receive the field-specific 422 on those routes, so each backend change that enforces them must ship together with a compatible AssabAPP release. No `shifts.contract_v2_enforced` config is introduced. **D6 — PROPOSED — MAHMOUD REVIEW REQUIRED:** preserve legacy `variance`/`variance_type` semantics and wire types. Proposed additive decimal-SAR keys: `expected_cash`, `counted_cash`, signed `cash_variance` (negative shortage), `cash_variance_type` (`shortage|surplus|balanced`). No runtime implementation is implied.

## S1-07 APPROVE A implementation — 2026-10-08

Mahmoud explicitly authorized the minimal additive liability model and implementation, with trusted count/opening/revision/receipt integration deferred to S1-10/S1-11. This supersedes the earlier S1-07 schema authorization blocker, not the acceptance status of the entire sprint.

**Decision:** add `shift_liability_allocations` (one immutable allocation snapshot per cashier shift/version) and `shift_liability_shares` (typed responsible actors and independent employee responses). Keep `shift_variance_details`, its cashier FK, its legacy statuses, and existing HTTP payloads unchanged. Two small tables are used instead of mixing new rows into `shift_variance_details`: the legacy variance writer deletes that table's shift rows, and its readers/listeners treat its `approved` status as financial authority. Adding columns there alone would allow old code to erase the new evidence or interpret a new manager share as an external factor. No legacy records/statuses are guessed or backfilled.

- Typed assignees are the existing `cashier`, `branch_manager`, or `employee` identities, resolved by an explicit allowlist, never a request-supplied PHP class. Legacy actors use their real branch and `branches.asab_company_id`; Admin Employee additionally requires its own `company_id`/`branch_id`. Missing company mapping fails closed. There is no new employee system and no inferred cross-domain identity mapping. Server adapters must authenticate/map the responding employee; passing an arbitrary client-selected model is forbidden.
- Money is integer halalas. Negative signed variance requires a complete positive-share allocation; no implicit cashier remainder or external-factor remainder. Zero/surplus has no employee shares. The S1-06 calculator remains the source of financial meaning; this service never reads legacy `CashierShift.variance`.
- Allocation version and server report revision/amount bind the approval. Reallocation supersedes, never deletes, the previous snapshot and resets approval/confirmation. Revision or amount drift makes prior approval unusable immediately at command/daily readiness checks. Old approval and objection remain historical; full report correction history/notifications are S1-11.
- Original cashier allocation requires owner confirmation. Manager correction requires a reason and can receive final manager approval without cashier reconfirmation. A manager assignee must explicitly approve their own share. Employee response is independent, and objection does not prevent manager approval. No receipt/custody/payroll side effect is dispatched.
- `DailyLiabilityGuard` requires the enclosing submit transaction and locks the workday. It consumes a complete server-resolved report/required-transfer set, rechecks report revisions and approvals, and requires confirmed receipt evidence for each required transfer. Unrelated transfers are not queried. It does not equate handover approval with receipt.

**Rollout boundary:** `LiabilityEvidenceSource` is bound to `UnavailableLiabilityEvidence` by default, returning a conflict instead of inventing evidence. There are no new HTTP routes and no activation of the new guard on legacy daily submit in this change. S1-10/S1-11 must supply/lock actual source-backed report revisions, counted cash/opening inputs, company identity mapping, and complete daily transfer membership/receipt evidence before the new services are connected to real routes. The current legacy submit remains unchanged and non-compliant with the target guard; it must not be advertised as enforcing S1-07.


## S1-08 Phase 1 — additive receipt and transfer identity

Implementation started at `c018fa01`; Phase 1 was committed at `9fc8ebacf99d23a1b43d27101420be65e4f5e5f0` and Phase 2 at `569d00e77dea922c03782c001c3c26c2cd30cba9`. Both remain **ready for review / not accepted**. Phase 1 added stable report identity (`shift_report_aggregates`, `shift_report_revisions`), manager-to-cashier transfer requests (`branch_manager_cash_transfers`), and immutable receipt facts (`cashier_shift_handover_receipts`). Existing cashier handovers gain a nullable report-revision FK; custody and personal-ledger rows gain nullable receipt FKs plus per-receipt uniqueness. Additive migration `2026_10_08_000004_add_manager_recipient_receipt_fields.php` was added in **Phase 2**; it adds typed manager receiver/workday/confirmer FKs and makes the cashier-only receipt fields nullable. No applied migration was changed.

Report revisions are UUID identities with a monotonically increasing number per source report. They store only source/actor identity and timestamps: no report snapshots, historical payload, correction reason, or correction API. Relevant cashier close/handover and manager close/daily-close update paths advance the identity in their existing transaction. A pending request is rejected at confirmation if its bound current revision has changed.

`ShiftTransferReceiptService` is the only writer of confirmed receipt facts and validates that exactly one typed source request is present. Confirmation must exactly equal the current request; mismatch returns `HANDOVER_AMOUNT_MISMATCH_CORRECTION_REQUIRED` before receipt/effects/state changes. An intentional smaller amount requires reject → correct → exact confirm. Existing shift-history records retain prior/requested and attempted/confirmed amounts, correction actor/time/reason, report revision, and submitted variance evidence; `actual_shortage` does not create an S1-07 liability allocation. Receipt source uniqueness and unique receipt references on effects prevent duplicate financial effect; generic replay protection remains S1-09.

For cashier-to-cashier handover, manager review writes review history only and leaves the request confirmable by the named cashier; the cashier's exact confirmation creates the receipt. For cashier-to-manager handover, only the addressed manager's exact confirmation is physical receipt evidence. It binds the manager/workday and report revision and atomically posts cashier `Handover Sent` debit, manager `Total Sales` credit, request state, and audit, all receipt-linked where financial. The manager-to-cashier command remains a service boundary, not a new public route. Admin bridge projection from a completed cashier report is non-authoritative and runs after commit.

**Lock order:** Manager close/correction locks `BranchManagerShift`, then `CashierShift` rows in deterministic ascending ID order, then a request/transfer row where applicable. Manager-to-cashier transfer request/confirmation locks `BranchManagerShift → destination CashierShift → transfer`. Cashier-to-cashier confirmation locks source and destination `CashierShift` rows in deterministic ascending ID order, then handover. Cashier-to-manager receipt confirmation locks `BranchManagerShift → source CashierShift → handover`. Mutable request state is re-read after the relevant locks. The non-authoritative manager statistics projection runs after commit and must not reacquire `BranchManagerShift` while a cashier transaction holds a `CashierShift` lock. Required authoritative financial writes remain inside the transaction; only non-authoritative projections may run after commit. Report aggregate rows are locked when incrementing revisions inside the caller's transaction. SQLite tests do not prove MySQL deadlock safety; MySQL/deployment-equivalent concurrency and production table-lock impact remain follow-ups.

Deferred: S1-09 generic idempotency/replay; S1-10 trusted counted cash and source-backed signed variance; S1-11 full immutable correction snapshots/history, public liability routes, complete daily-submit/reopen gate and day-scope membership. No S1-10/S1-11 evidence is fabricated here.

## S1-08 Phase 2 — close/approval atomicity addendum

Phase 2 leaves the Phase 1 migration unchanged. `ShiftEndService` commits the cashier report, sales breakdown, revision, report history, declaration custody row, and legacy variance rows together. `CashierShiftObserver` defers close/start bridge events and manager-statistics projection until commit; these are projections, and their failures are logged without changing the committed cashier report.

Admin shift close now locks and re-reads the native `asab_shifts` row before deriving expected cash and creating its pipeline operation. Final approval locks and re-reads the operation, then in the same transaction writes final state and approval step, locks/re-reads the native shift, posts any required employee allocation, and closes the shift. A shortage without a resolvable allocation fails closed. Required failures roll back the operation, step, movement, and close together. Remaining final-approval listeners are post-commit projections; their exceptions are logged.

Cashier-to-cashier manager review locks `CashierShift → handover/status`, writes manager review history, and leaves the request pending for the named cashier; it performs no liability adjudication or receipt write. Cashier-to-manager confirmation locks the manager workday before the cashier source and handover, then `ShiftTransferReceiptService` atomically creates the immutable manager-bound receipt, cashier debit, manager `Total Sales` credit, state, and audit. `VarianceRecorded` remains an independent legacy variance effect; receipt confirmation does not finalize liability evidence.

**Phase 2 lock orders:** cashier close/variance approval: `CashierShift → report aggregate/revision or handover/status/detail → required custody/ledger/history rows`; cashier-to-cashier confirmation: source and destination `CashierShift` rows ascending ID → handover; manager transfer: `BranchManagerShift → destination CashierShift → transfer`; manager close/correction: `BranchManagerShift → CashierShift` rows ascending ID → request/transfer. Admin close: `Shift → newly created Operation/ApprovalStep` (not visible until commit); Admin final approval: `Operation → Shift → EmployeeMovement/ApprovalStep`. No MySQL concurrency test was run; SQLite rollback tests are not deadlock proof.

S1-08 remains **ready for review / not accepted**. No generic replay framework (S1-09), trusted physical count/evidence adapter (S1-10), or full immutable revision/correction subsystem, public liability routes, or daily-submit/reopen wiring (S1-11) is included. MySQL concurrency/deadlock and production DDL review remain deployment follow-ups. The focused correction history here is limited to the current transfer request correction contract.

### S1-08 correction-pass decisions (local, uncommitted)

D12 was approved by Mohamed on 2026-10-08: a branch has one uniquely assigned active manager, and only that manager may confirm or reject a manager-addressed handover. The domain guard rejects a second active assignment on create, activation, restore, or branch transfer; recipient resolution fails closed when legacy rows contain zero or multiple active assignments. No database uniqueness migration is introduced before the read-only deployed-data preflight and MySQL DDL review. Manager workday handovers and `canEnd` use the addressed manager identity. Lock orders above remain unchanged.

D11 records the recipient-confirmed physical amount at rejection conceptually (500 requested, 480 physically confirmed means 480 pending/rejected incoming linked to the request, not surplus); structured authoritative evidence is S1-10. D13 treats late receipt after daily submission as a cash movement at the receipt timestamp, without reopening or rewriting historical sales. S8-09 keeps self-shortage ledger posting for final manager liability approval in S1-11, with no release before that task. S8-12 uses a zero-pending-handover drain-before-deploy gate and no normal-deployment backfill. See `s1-08-deployment-readiness.md`.

# S1-01 financial shift and cash-cycle API contract (source trace)

**S1-01 disposition: Ready for review; not Accepted.** The isolated 3310 environment and six focused test files passed (41 tests, 144 assertions). This contract remains a source trace: those tests do not verify every endpoint, role, payload, monetary conversion or event effect listed here. No TO-BE requirement is an AS-IS guarantee. Mahmoud owns business-rule acceptance; S1-02 has not started. See [verification.md](verification.md).

**Evidence boundary:** drafted from static source on 2026-10-07. Laravel route registration was verified (exit 0; 1,734 API routes). Later focused tests exercised their own in-process Laravel requests/services against the disposable MySQL test schema; they were not a complete API contract suite or a deployed HTTP-server check. Unless tied to a named passing assertion, status/envelope statements below remain static source findings. “AS-IS” means inspected behavior; “TO-BE” means the approved target, not delivered acceptance.

This supplements [route-map.md](route-map.md) and [baseline.md](baseline.md). Target identifiers are BR-01–BR-25 and AC-01–AC-21 in `project-docs/Assab-ERP-Cash-Cycle-Business-Rules-v2.0-EN.md`; Sprint acceptance identifiers are A01–A18 in the Sprint 01 plan §7. A01–A13 cover financial calculations, lifecycle and atomicity/replay; A14–A16 cover authentication/session/retry behavior; A17 covers expense/shift separation; A18 covers Dashboard data behavior. Expense-specific scenarios are AC-14–AC-21. S1-05 owns the approved API blueprint; this document records source and target gaps.

## Evidence and common contract rules

* **Legacy shift API (L):** source exposes `/api/...` and `/api/v1/...` aliases. Exact route definitions: `Modules/Shift/routes/api.php:23-24, 157-159, 174-190, 226-229, 284-286, 335-386`. Shared identity middleware is Sanctum plus `branch.manager.or.cashier`, `branch.manager`, or `cashier`; resource ownership/branch checks happen in handlers. `log.throttle` is request logging; do not read it as rate limiting (`docs/sprint-01/route-map.md`, “Registration and shared boundaries”).
* **Company API (N):** `/api/v1/company/me/...` uses Sanctum, `ResolveTenant`, `IdempotencyKey`, and `AuditMutations`; nested role middleware is on the route groups. Current route registrations include branch open/close and accountant close (`Modules/Admin/routes/api.php:434, 480-484, 853-857, 923-924`). Exact tenant and role middleware are described in `bootstrap/app.php` and route map. Company scope derives from authenticated tenant/role; do not assume legacy branch middleware provides tenant isolation.
* Unless a handler returns an explicit code, Laravel JSON responses default to **200**. These are static code-derived response codes, not HTTP-tested status codes. Auth middleware is expected to reject unauthenticated calls (normally 401) and role/ownership checks return 403; exact envelope from middleware is **REQUIRES VERIFICATION**.
* The legacy `successResponse` handlers return a success/message/data-style envelope; some use raw `response()->json`. Error envelopes vary between `{success:false,message,errors?}` and `{success:false,message}` or BaseController output. There is no single verified error schema. Validation is commonly inline `Validator`, not a FormRequest; `Modules/Shift/Http/Requests/EndShiftRequest.php` is not used by the end route (`route-map.md`, “end”).
* Monetary unit rule: legacy CashierShift monetary casts/resources are decimal SAR (`Modules/Shift/app/Models/CashierShift.php:54-62`; `Modules/Shift/app/Transformers/ShiftDetailResource.php`); API names generally do not encode unit. Company API fields explicitly named `*Halalas` and Dashboard types use integer halalas (`dashboard/artifacts/mockup-sandbox/src/api/queries/shifts.ts:113-160`, `src/api/types/company.ts:763-795`, `src/lib/money.ts`). Flutter models use `double` or parse integer values; neither is an authoritative unit declaration. Any unspecified unit below is **UNKNOWN**.

## Endpoint contracts

### L1 — Start cashier shift

* **Method / URL:** `POST /api/cashier/shifts/{shift}/start` and `/api/v1/cashier/shifts/{shift}/start`; manager-start alternative `POST /api/branch-manager/shifts/start-by-manager/{shiftId}` and `/api/v1/...` (`Modules/Shift/routes/api.php:335-336, 157-159`).
* **Authentication / scope:** Sanctum; cashier middleware for self-start; cashier must own the scheduled shift. Manager-start is branch-manager route group and handler validates branch/scheduled shift (`CashierShiftController::startShift` around lines 299-343; `startShiftByManager` around 886). Tenant middleware is not present on L routes.
* **Request:** path ID only; no body fields. Amounts: none.
* **Validation/state:** shift must be `NOT_STARTED` or `REASSIGNED`, scheduled date today; manager-start is for an eligible pending cashier shift. Exact manager handler conditions should be verified against its full source before implementation.
* **Response/errors:** source success envelope contains `ShiftDetailResource` and progress; static default success 200. Invalid state 400; missing/inaccessible shift 404; auth/ownership 401/403; caught exceptions may be 500. Runtime status/envelope **NOT VERIFIED**.
* **Effects/retry:** status/start timestamp and related shift start effects; transaction/row locking/idempotency not established (**UNKNOWN**). No L idempotency middleware.
* **Consumers:** Flutter `shift_management_remote_data_source.dart` has manager-start call around line 160; Dashboard uses Company open route instead.
* **BR/AC/Sprint:** BR-12; AC-12/13; A09/A10. **AS-IS:** start can be a state flip independent of a receipt confirmation. **TO-BE:** shift opening cash must derive from confirmed receipt; settings alone is not proof. **GAP:** opening source and zero/omitted behavior are not contractually represented here.

### N1 — Open branch shift (Dashboard path)

* **Method / URL:** `POST /api/v1/company/me/branch/shifts/open` (`Modules/Admin/routes/api.php:923`).
* **Authentication / scope:** Sanctum + tenant resolution + idempotency + audit; role `branch`; branch is derived from current role/tenant. Dashboard client base uses `/api/v1` (`dashboard/artifacts/mockup-sandbox/src/api/client.ts:9-24`).
* **Request:** `cashierEmpNumber` or `cashierId`: optional nullable string; `openingCashHalalas` or `registerOpeningHalalas`: optional nullable integer >=0 (`BranchCompanyController::openShift`, around lines 320-365). Amount unit is halalas. If omitted, handler uses configured opening float; this is not proof of cash actually received.
* **Response/errors:** source success is **201**, with a bare ShiftPresenter object through `created()` (`Modules/Admin/app/Http/Controllers/Company/BranchCompanyController.php:366`; `Modules/Admin/app/Support/AsabResponse.php:28-31`). Existing active/late cashier shift throws `SHIFT_ALREADY_OPEN` with 409 (`BranchCompanyController.php:336-338`). Validation 422; auth/role 401/403; missing tenant/branch 404/403 are source-path expectations, not a comprehensive runtime status test. Full field coverage still requires contract verification.
* **Effects/retry:** creates/opens company shift and audit/realtime notification; idempotency middleware stores successful responses, but current middleware uses global key lookup and lacks actor/route/payload binding and pre-effect reservation (`route-map.md`, middleware section). Transaction/replay concurrency guarantee **NOT ESTABLISHED**.
* **Dashboard:** `useOpenShift` sends the aliases above (`queries/shifts.ts:113-131`); Dashboard Shift DTO is `types/company.ts:763-795`; money conversions are in `src/lib/money.ts`.
* **Flutter:** no matching Company API consumer found in the inspected shift datasource; Flutter remains on L.
* **BR/AC/Sprint:** BR-12/14; AC-12/13; A09/A10. **AS-IS:** configured opening may populate the shift. **TO-BE:** only confirmed handover becomes opening cash; configured value cannot masquerade as receipt. **GAP:** contract has no explicit confirmed-receipt reference in this request.

### L2 — End cashier shift without handover

* **Method / URL:** `POST /api/cashier/shifts/{shift}/end`, `/api/v1/...`; manager alias `/api/branch-manager/shifts/{shift}/end`, `/api/v1/...` (`Shift/routes/api.php:353-354, 174-175`).
* **Auth/scope:** Sanctum; legacy cashier-or-manager group; handler checks cashier ownership or manager’s branch and requires `IN_PROGRESS` (`ShiftEndController::endShiftOnly`, around lines 42-147 and `getShiftForUser` around 789).
* **Request:** `total_sales` required numeric >=0; `cash_collected`, `card_payments` optional numeric >=0; `aggregators[]` optional, each `aggregator_id` required/existing and `amount` required numeric >=0, optional notes string <=255; optional `pos_receipt` JPG/JPEG/PNG/PDF <=5120 KB; optional `variance` object: `responsibility_type` in `self|self_and_others|other_factors|mixed`; `current_cashier_amount` required for self_and_others/mixed; `other_cashiers[]` IDs and nonnegative amounts; notes <=255; reason conditionally required <=500; supporting files PDF/PNG/JPEG/JPG <=5120 KB. Aggregator IDs are checked unique. No independent `counted_cash` request field. Numeric amount units are not encoded; legacy model/resource indicates decimal SAR. Null/omitted channel semantics are inconsistent (optional/default zero), and must be preserved until agreed.
* **Response/errors:** success includes `shift` resource, `variance`, and `summary` with total/net/VAT/sales breakdown/opening balance/handover status and next actions (controller around 147-187). Static success 200; validation 422; missing/inaccessible 404; wrong state 400; caught exception 500. Error shape differs by branch. HTTP behavior **NOT RUNTIME VERIFIED**.
* **Effects/transaction:** ShiftEndService writes completed state, end time, sales/VAT/net, receipt/breakdown/history in a DB transaction; optional custody posting and variance detail/file recording occur outside that service transaction or in separate transactions. Observer/event bridge may synchronously update Admin projection. No idempotency middleware or durable command key. Details in `route-map.md` “end”.
* **Consumers:** Flutter multipart `EndShiftRequest` calls manager end route (`AssabAPP/lib/features/shift_module_features/shift_management/data/data_source/shift_management_remote_data_source.dart:119-129`; model `.../end_shift_request.dart:8-70`). Dashboard shift hooks do not post this endpoint; Dashboard consumes company shift projection and operation queue.
* **BR/AC/Sprint:** BR-01–04, BR-07–11, BR-25; AC-01–06, AC-21; A01–A05/A18. **AS-IS:** legacy VAT calculation uses total×15%, variance uses total less reported channels, and no separate count is accepted (route-map “end”; `ShiftEndService.php:185`, `CashierShift.php:212-219`). **TO-BE:** gross VAT-inclusive; net=gross/1.15; expected cash=gross−cards−apps+confirmed opening; variance=counted−expected; complete approved shortage allocation required before report closure; surplus belongs to branch. **GAP:** formula/sign, missing counted amount, optional/partial allocation and transaction boundary conflict with approved rules.

### L3 — End shift with requested handover

* **Method / URL:** `POST /api/{cashier|branch-manager}/shifts/{shift}/end-with-handover` and `/api/v1/...` (`Shift/routes/api.php:355-356, 176-177`).
* **Auth/scope:** same legacy auth and sender scope as L2. Explicit branch manager recipient is checked against sender branch; cashier recipient branch/availability check is **not established**.
* **Request:** L2 sales/channel/receipt/variance fields plus `handover_to_type` optional `cashier|branch_manager`; `next_cashier_id`/`branch_manager_id` conditionally required, nullable existing ID; `handover_amount` required numeric >=0; `handover_notes` nullable string <=500. Amount unit is inferred as SAR from legacy model, but payload does not declare it. If manager type is set without ID, handler chooses first active branch manager (`ShiftEndController.php:264-305`).
* **Response/errors:** legacy shift resource, handover details and summary; success default 200; validation 422, not found 404, wrong state 400; caught errors 500. Exact envelope details around controller lines 350-445; no live HTTP verification.
* **Effects/transaction:** outer service transaction calls end-only, records pending handover and optional variance; nested transaction behavior plus observer/bridge and best-effort custody are described in route-map “end-with-handover”. Pending request is not confirmed receipt. No explicit idempotency.
* **Consumers:** Flutter `EndShiftRequestWithHandOver` model serializes decimal-like doubles and may send `to_branch_manager`; backend expects `branch_manager_id` or `handover_to_type=branch_manager` (`end_shift_request_with_variance.dart:7-70`; datasource line 131). This is a compatibility risk. Dashboard has no direct caller.
* **BR/AC/Sprint:** BR-05–06, BR-12–17, BR-24–25; AC-07–13; A06–A13/A18. **AS-IS:** report completes while a pending request is recorded; handover amount is requested, not actual confirmed transfer. **TO-BE:** report submission and receipt are independent facts; partial actual receipt alone transfers and supplies opening; preserve rejected revisions and receipts. **GAP:** request and receipt are conflated in parts of legacy flows; missing revision/evidence semantics and app/backend recipient-field mismatch.

### L4 — Start handover / record handover

* **Method / URL:** `POST /api/{cashier|branch-manager}/shifts/{shift}/start-handover` (L aliases); separate `POST /api/{cashier|branch-manager}/shifts/{shift}/handover` (`routes/api.php:357-358, 375-376`).
* **Request:** start-handover takes type, conditional recipient ID, required nonnegative `handover_amount`, optional notes <=500 and optional variance fields/files as in L2. Record-handover takes `next_cashier_id` required existing, `handover_amount` required >=0, notes nullable <=500. Amount units inferred decimal SAR, not named.
* **Auth/scope/state:** legacy group; sender/branch validation; state must permit handover. Record-handover targets cashier, and completed/eligible shift checks are handler/service specific. See `ShiftEndController` around 450-621 and `ShiftHandoverController` record method; exact role-specific branch cross-check for every recipient is **REQUIRES VERIFICATION**.
* **Response/errors/effects:** default 200 success; validation 422, permission 403, missing 404, invalid transition 400, caught exception 500. Persists a pending handover/status/history and may write custody/variance; no route idempotency. Exact transaction boundary varies by service and is summarized in route-map.
* **Consumers:** Flutter `@POST .../start-handover` at datasource line 154 and `HandOverRequest`; no Dashboard caller identified.
* **BR/AC/Sprint:** BR-05–17, BR-24–25; AC-07–13; A06–A13/A18. **AS-IS:** requested amount is represented separately from pending/approved state but some approval helpers may assign opening before confirmation. **TO-BE:** recipient-confirmed actual amount alone changes custody/opening. **GAP:** confirmation semantics, partial receipt, and sender remainder need explicit endpoints/state contract.

### L5 — Accept / approve receipt

* **Method / URL:** recipient cashier `POST /api/cashier/shifts/{shift}/handover/accept` and `/api/v1/...`; branch manager `POST /api/branch-manager/shifts/{shift}/handover/approve` (L aliases); manager daily handoff `POST /api/branch-manager/workday/handoffs/approve` (`Shift/routes/api.php:377-378, 247`).
* **Request:** cashier accept has optional `comment` string (no explicit validation established); manager handoff endpoint requires `handover_id` existing `cashier_shift_handovers` row. Shift-manager approve path accepts optional manager comment per controller. No amount supplied; amount unit is inherited from handover record.
* **Auth/scope:** cashier recipient only; manager accepts a handover addressed to manager and same branch; manager workday handler requires branch-manager role and branch match. The manager workday endpoint uses body ID rather than route shift ID.
* **Response/errors:** default 200; invalid handover state 400; unauthorized 403; missing 404; controller exceptions 500. Cashier response is success/message; manager path returns handover + message. Actual status/envelopes are untested.
* **Effects/transaction:** HandoverService marks accepted/approved, advances sender and incoming shift/opening, history/status, and may trigger events; service internals have DB transaction. Whether opening equals *actual received* is not established. No idempotency middleware; duplicate race behavior UNKNOWN.
* **Consumers:** Flutter has accept/approve calls in shift datasource; Dashboard does not directly invoke legacy handover. No Flutter modification proposed.
* **BR/AC/Sprint:** BR-05–06, BR-12–14, BR-16, BR-25; AC-07/09/10/12; A06/A09/A11/A18. **AS-IS:** approval can advance handover state and opening. **TO-BE:** record recipient-confirmed amount, including zero/partial; confirmed movements are immutable and a new shift gets only confirmed amount. **GAP:** acceptance payload cannot report actual received amount; confirm whether approval means receipt and what happens on mismatch.

### L6 — Reject / correct / manager rejection decision

* **Method / URL:** cashier/manager `POST /api/{role}/shifts/{shift}/handover/reject`; cashier correction `POST /api/cashier/shifts/{shift}/handover/edit`; manager decision `POST /api/branch-manager/shifts/{shift}/handover/rejection-decision` (`Shift/routes/api.php:381-386, 189-198`).
* **Request:** reject has optional `rejection_reason` string <=500, optional manager comment <=500 and optional rejection file array (PDF/PNG/JPG/JPEG <=5120 KB; precise route-specific requiredness varies). Edit requires `handover_amount` numeric >=0; `handover_notes` nullable <=500. Rejection decision allows `decision` in `approve_rejection|request_corrections`, manager comment nullable <=1000; absent decision defaults to request corrections. Amount unit legacy SAR inferred.
* **Auth/scope/state:** recipient/branch manager role and same-branch checks; only rejected/eligible records can be changed; limits and override paths exist. Exact transition requires `ShiftHandoverController.php` methods `rejectHandover`, `editHandoverAfterRejection`, `processRejectionDecision` (around lines 80-250).
* **Response/errors:** success default 200; validation 422; permission 403; missing 404; wrong state/limit 400; exception 500. Response may report rejected handover or request-corrections state. Not HTTP-verified.
* **Effects/transaction:** reject path changes handover/status and may delete/reset associated state; correction mutates current request; manager can approve rejection/finalize. No idempotency route middleware. Detailed source consequences are in route-map’s reject/resubmit sections.
* **Consumers:** Flutter datasource calls rejection-decision; model includes decision/comment. Its handover edit payload edits amount/notes, while approved BR requires all report fields to be correctable. Dashboard generic operation approvals are a different company workflow.
* **BR/AC/Sprint:** BR-13, BR-15–17, BR-24–25; AC-08/10/11; A07/A08/A12/A18. **AS-IS:** correction is handover-focused; finite rejection/manager override exists; prior evidence preservation is not shown. **TO-BE:** correct all report fields; preserve versions and confirmed receipts; no fixed rejection cap or recipient override. **GAP:** correction scope/state machine/evidence policy conflict with target.

### N2 — Company shift close (Dashboard path)

* **Method / URL:** `POST /api/v1/company/me/shifts/{id}/close` (accountant/head role); aliases `POST /api/v1/company/me/branch/shifts/{id}/close` (branch role) and `/api/v1/accountant/shifts/{id}/close` (accountant/head) (`Admin/routes/api.php:480-484, 853-857, 923-924`).
* **Request:** `cashActualHalalas` required integer >=0; aliases `cashInDrawer`/`cashInDrawerHalalas` accepted; `cardTotalHalalas` optional integer >=0; `aggregatorTotalsHalalas` optional integer >=0; `notes` nullable string. Amounts are integer halalas. Tenant/branch derived from authenticated route context; close ID is path.
* **Auth/scope:** Sanctum + tenant role scope; company middleware includes idempotency/audit. Accountant controller additionally checks assigned branch.
* **Response/errors:** ShiftPresenter under Admin `run/ok` wrapper; success default 200; validation 422; missing/out-of-scope 404/403; already closed 409; manager shift not closable 422 (source-derived; runtime not HTTP-tested). Shape detail: `Modules/Admin/app/Http/Controllers/Accountant/ShiftController.php:105-128`, presenter and BaseController helper.
* **Effects/transaction:** `ShiftCloseService` permits active/late states, computes expected and variance in integer halalas, transactionally changes shift to pending_review and writes operation/snapshot (`Admin/app/Services/ShiftCloseService.php:44-105`). Middleware audit and idempotency are separate; their all-or-nothing durability is **NOT ESTABLISHED**. Existing expected formula clamps sales less card/apps at zero, then adds opening float (route-map “close”).
* **Dashboard:** `useCloseShift` in `queries/shifts.ts:133-160`, Company Shift DTO `types/company.ts:763-795`, `money.ts` conversions. Related generic review uses `queries/operations.ts` approve/final-approve hooks; this is not a legacy handover API.
* **Flutter:** no use of this company route found; remains on legacy SAR payload.
* **BR/AC/Sprint:** BR-01–04, BR-11, BR-25; AC-01/06/21; A01/A02/A18. **AS-IS:** integer-halalas close accepts counted cash and writes review operation. **TO-BE:** formula/sign as BR-01/03, verified breakdowns, no unrelated expense effect, no duplicate financial writes. **GAP:** source formula clamp/alias semantics need decision and parity with L financial report is not established.

### N3 — Shift variance allocation

* **Method / URL:** `POST /api/v1/accountant/shifts/{id}/variance-allocations` and `POST /api/v1/company/me/shifts/{id}/variance-allocations` (`Modules/Admin/routes/api.php:434, 483-484, 853-857`). Handler is `Accountant\ShiftController::varianceAllocations` (`Admin/app/Http/Controllers/Accountant/ShiftController.php:135-153`).
* **Request:** required `allocations` array min 1; each item has `amountHalalas` required integer >=1 and employee identity by `employeeId` and/or `empNumber` per controller validation. Total must reconcile to shortage; employee must be in branch. Unit integer halalas.
* **Auth/scope:** accountant/head on `/accountant`; company role on `/company/me`; tenant and assigned branch scope. Confirm exact role middleware on the company nested route before contract freeze.
* **Response/errors/effects:** service records allocation and operation/review data; transaction and exact response/error statuses are **REQUIRES VERIFICATION**. Not invoked. Dashboard `useShiftVarianceAllocations` is in `queries/shifts.ts:166-181`; confirm which alias it uses before pinning.
* **BR/AC/Sprint:** BR-07–10, BR-25; AC-02–05/21; A03–A05/A18. **AS-IS:** API supports allocations but presence of allocation alone must not imply employee acceptance or manager final liability approval. **TO-BE:** whole shortage, in-branch explicit shares; employee acceptance/objection and manager approval separate. **GAP:** check dedicated acceptance, objection, and final-approval contracts and ordering; this document does not establish them.

### L7 — Branch-manager daily report submit / reopen

* **Method / URL:** `POST /api/branch-manager/workday/daily-close/submit` and `/api/v1/...`; reopen is `POST /api/branch-manager/workday/daily-close/reopen` and `/api/v1/...` (`Shift/routes/api.php:264-272`).
* **Authentication/scope:** Sanctum + `branch.manager`; controller selects the authenticated manager’s shift for today. Tenant middleware is absent on these legacy aliases.
* **Request:** submit accepts only optional `final_notes` string <=1000; reopen requires `reopen_reason` string <=500. No amount fields; submitted financial values are persisted on manager shift and linked handovers; their unit is inherited/ambiguous.
* **State/response:** submit requires a completed shift and not previously submitted; sets daily report submitted, timestamp, notes, and `can_reopen`; returns manager shift resource and message with static 200. Validation 422; already submitted 400; missing shift 404; exception 500. Reopen requires `can_reopen` and submitted state; invalid transition 400; it records reason/time and clears submitted state. Runtime HTTP not tested.
* **Effects/transaction:** submit transaction updates daily report state and marks included approved handovers `daily_closed_at`, then clears cache and emits `DailyReportSubmittedEvent` outside that transaction (`BranchManagerShiftController.php:730-789`). Event bridge and duplicate/reopen effect behavior require verification. No route idempotency middleware.
* **Consumers:** no Dashboard mutation hook found; Flutter exposes manager daily-close models/routes, but the inspected remote source does not call daily-close submit. **REQUIRES VERIFICATION** across full app references.
* **BR/AC/Sprint:** BR-05–11, BR-15–16, BR-24–25; AC-02–07/10/21; A02–A08/A18. **AS-IS:** submit requires completed shift but does not show shortage-allocation/manager-liability approval gates; it treats included approved handovers as daily-closed. **TO-BE:** report submission closes report only; sender remains liable for unconfirmed/partial cash, and shortage approval gates must be honored. **GAP:** receipt and liability gates, event atomicity, and reopen/re-submit revision history need explicit contracts.

### E1 — Expense submit / resubmit (cash-cycle custody)

* **Method / URL:** `POST /api/branch-manager/expenses/{expense}/submit` and `/resubmit` (`Modules/Expense/routes/api.php:21-24, 145-149`; same endpoint aliases depend on module loader and must be confirmed from runtime route JSON before client pinning).
* **Authentication/scope:** Sanctum + shared `branch.manager.or.cashier.or.brand.owner` middleware. Handler requires `expense.branch_manager_id === auth()->id()` (`ExpenseController.php:216-257`); tenant scope otherwise **UNKNOWN** for these legacy routes.
* **Request:** path UUID; no body fields. Monetary amount is held on expense resource/model; unit requires inspection of resource/model casting before reliance and is not declared by submit route.
* **Validation/state:** service accepts eligible draft; resubmit requires `status=rejected`, changes to pending, clears current rejection/decision fields, writes timeline and emits submit event (`ExpenseApprovalService.php:126-149`). Body validation absent.
* **Response/errors/effects:** success returns ExpenseDetailResource and message; default 200. Unauthorized 403; service exception passed to `errorResponse` without explicit status, so effective failure status is **UNKNOWN**. Event triggers notification/ASAB bridge; ledger/custody debit timing and transaction/idempotency are **REQUIRES VERIFICATION** from `submitExpense` source and listeners. Throttle `expense-write` is attached but status/config must be runtime verified.
* **Consumers:** Dashboard expense queries expose reads/operations; no direct submit mutation found in inspected hooks. Flutter expense create/edit exists; submit caller **REQUIRES VERIFICATION**. BR scope included because custody rules are in target even though this is not a shift route.
* **BR/AC/Sprint:** BR-18–22/25; AC-14–17/21; A17 for expense/shift separation (expense-specific acceptance is in AC). **AS-IS:** submit/resubmit updates approval state, event, timeline; precise debit/reversal absent from this source slice. **TO-BE:** debit once on submit; approve does not debit again; reject reverses once; resubmission of the same corrected request starts a retained cycle and debits once. **GAP:** ledger timing, revision identity, custody insufficiency, transaction and retry guarantees require proof.

### E2 — Expense approve / reject

* **Method / URL:** `POST /api/branch-manager/expenses/{expense}/approve|reject` (shared group; routes `Expense/routes/api.php:31-35`; controller also documents brand-owner URLs, but registered route group must be used). No separate `brand-owner/...` route should be assumed from docblocks.
* **Authentication/scope:** Sanctum + shared middleware; controller explicitly requires `BrandOwner` instance or returns 403 (`ExpenseApprovalController.php:79-83,106-110`). Approval authorization therefore differs from BR role matrix’s “owner or authorized brand manager”; tenant/brand scoping in repository is **REQUIRES VERIFICATION**.
* **Request:** approve has no body. Reject requires `reason` string length 10–500 (`ExpenseApprovalController.php:112-122`). Amounts are not submitted; the persisted expense amount unit is not declared in this endpoint.
* **Response/errors:** approve returns ExpenseDetailResource; service conflicts return 400. Reject validation returns 422; other error default status is helper-dependent **UNKNOWN**. Success defaults to 200. HTTP not tested.
* **Effects/transaction:** service changes approval status/decision metadata, timeline, fires event (`ExpenseApprovalService.php:55-100`); its visible code does not wrap these updates/event in explicit DB transaction. Approval event bridges operation closure per service comment. Whether ledger is touched is not shown in these methods.
* **Consumers:** Dashboard operation approve/final-approve is distinct; expense approval bridge behavior needs confirming to avoid double decision. Flutter compatibility consumer not identified.
* **BR/AC/Sprint:** BR-18–23/25; AC-14–21; A17 for expense/shift separation (expense-specific acceptance is in AC). **AS-IS:** brand-owner only; first decision checks pending/not accounting-owned; no revision ID is submitted. **TO-BE:** owner or delegated brand manager with scope; current revision only, one decision wins; accounting review cannot approve expense. **GAP:** role mismatch, no explicit revision concurrency token, and transaction/idempotency/ledger atomicity unverified.

## Endpoint-to-consumer compatibility matrix

| API flow | Dashboard | AssabAPP (read-only reference) | Compatibility finding |
|---|---|---|---|
| Legacy cashier start/end/handover | No direct mutation caller found in inspected `queries/shifts.ts`; it consumes Admin projection/operations | `shift_management_remote_data_source.dart` uses `/api/v1` legacy endpoints; `EndShiftRequest` and `EndShiftRequestWithHandOver` use `double` monetary fields | Legacy API aliases exist; SAR unit inferred, independent counted cash absent |
| Company shift open/close | `useOpenShift`, `useCloseShift`, `useShiftVarianceAllocations`; integer-halalas DTOs | No matching company endpoint found | Separate API generation and units; do not silently wire Flutter to company routes |
| Legacy receipt accept/reject/edit | No direct Dashboard legacy route consumer found | Handover calls and request/response models in `shift_management_remote_data_source.dart` and `data/model/shift/` | App recipient naming (`to_branch_manager`) differs from backend (`branch_manager_id` / type); corrections do not represent all report fields |
| Expense submit/resubmit/decision | Dashboard operations/expense read hooks; exact mutation use must be verified | Expense feature has draft/edit model; submit caller **UNKNOWN** | Generic operation decision and direct brand-owner decision can overlap; map before migration |

## AS-IS / TO-BE / GAP summary

| Concern | AS-IS source evidence | TO-BE approved rule | GAP / compatibility risk |
|---|---|---|---|
| Units | L uses decimal-looking SAR and unqualified numeric fields; N uses integer halalas | A single canonical internal unit with explicit boundary conversion | Major silent 100×/rounding risk; no agreed adapter contract |
| VAT / net | Legacy service computes VAT as 15% of gross, net=gross−VAT | Gross includes VAT; net=gross/1.15, VAT=gross−net (BR-01) | Formula mismatch; historical records must not be recomputed silently |
| Expected cash / variance | Legacy no counted-cash field; variance formula differs; N accepts cashActualHalalas and computes its own expected value | Expected=gross−cards−apps+confirmed opening; variance=counted−expected (BR-03) | Two flows can produce conflicting values; need actual cash count and confirmed opening semantics |
| Receipt vs report | End request may complete report while pending handover; approval advances state | Report submission, cash responsibility, receipt are independent facts (BR-05/06) | Legacy state conflates or advances opening on approval without a separately supplied actual amount |
| Correction/history | Edit is handover-centric; rejection path may reset/delete; manager override/fixed limits exist | Correct all report fields; preserve revision/evidence/confirmed receipt; no fixed rejection cap/override (BR-15–17/24) | Breaking state/response behavior likely; protect Flutter’s old response parser |
| Expense | Submit/resubmit emits event; service approval edits statuses and timeline; ledger effects not established here | debit/reverse exactly once, sufficient custody, same request new review cycle, current revision first decision (BR-18–25) | Ledger timing, authorization, concurrency, idempotency and transaction atomicity not proven |
| Scope/auth | Legacy identity middleware; Admin tenant + role and assigned branch | Enforce role and tenant/branch in each transition | Cannot assume aliases share policy; verify each endpoint after design |

## Contract-breaking risks to resolve before implementation

1. Converting legacy numeric SAR to halalas without explicit versioned conversion or precise rounding changes existing clients’ values by 100×.
2. Replacing/requiring `cash_collected`, `handover_amount`, or accepting new `counted_cash` without preserving current Flutter multipart behavior breaks existing requests.
3. Renaming Flutter `to_branch_manager` to backend `branch_manager_id`, or changing handover response nesting/status values, is a wire break.
4. Changing end from terminal report completion to a separate submitted/review state can break consumers that infer finality from `shift.status`.
5. Tightening null/omitted/zero behavior can break the Flutter request, which omits some zero-valued payment fields; zero must remain distinct from missing.
6. Changing 200/400/422/409 or error envelope shapes can break client error handling; these are currently code-derived, not integration-tested.
7. Adding tenant/role checks to L aliases may reject clients that currently rely only on cashier/manager identity; preserving unauthorized access is not an option, but rollout needs compatibility planning.
8. Replaying/duplicating close, submit, approve, reject, or receipt actions can duplicate financial effects; current legacy routes lack explicit idempotency and Admin key scope/replay semantics are insufficiently bound.
9. Flattening L and N routes into one DTO risks mixing tenant models, role assumptions, persisted shift records, and money units.
10. Approval of an expense through both direct route and bridged Dashboard operation can create conflicting decisions unless one authoritative path is selected.

## Missing or inconsistent monetary-unit definitions

* L request fields `total_sales`, `cash_collected`, `card_payments`, aggregator `amount`, `handover_amount`, variance shares/counts, `opening_balance`, and daily-close totals have no unit suffix. Legacy casts and Flutter `double` imply SAR, but contract does not expressly promise this.
* `cashier_breakdown.*.variance` and daily `handover_amount` have no API unit declaration; classify **UNKNOWN** until resource casts and values are verified.
* Flutter generated models sometimes parse `handover_amount` as `int` while other shift models use `double`; this risks truncation and is not a unit contract (`AssabAPP/lib/features/shift_module_features/shift_management/data/model/manager_shift_management_models/final_handover.dart:56-79` vs `.../shift_model.dart:80`).
* N `openingCashHalalas`, `cashActualHalalas`, `cardTotalHalalas`, `aggregatorTotalsHalalas`, allocation `amountHalalas` explicitly mean halalas; Dashboard helper divides by 100 for SAR display and rounds SAR to halalas (`dashboard/artifacts/mockup-sandbox/src/lib/money.ts`).
* Expense model/resource amount units and custody ledger units have not been proven from this endpoint trace. Mark **UNKNOWN**, do not assume SAR or halalas based on UI formatting.
* API response values from `ShiftDetailResource`, `ShiftPresenter`, expense resources and event bridge have not been compared over HTTP or against DB values; runtime serialization and null precision are **REQUIRES VERIFICATION**.

## Decisions for Mahmoud

1. Which canonical unit should the backend use internally, and which versioned API adapters preserve legacy SAR while Dashboard uses halalas? Specify decimal precision and rounding.
2. What is the canonical field/endpoint for an independently counted drawer amount, and how should omitted, null, and explicit zero behave?
3. Should receipt be a distinct request/confirmation pair? Which actor confirms, how are partial/zero receipts represented, and what exact amount becomes next shift opening?
4. Which lifecycle state distinguishes report submitted, receipt pending/partial/confirmed, shortage allocated, employee accepted/objected, and manager final liability approval?
5. Should L routes remain compatibility adapters, and for how long? Which aliases and response envelope/status codes are promised to Flutter?
6. Resolve the `to_branch_manager` vs `branch_manager_id` mismatch and decide whether manager recipient may be auto-selected.
7. Which roles may submit, first-decide, review, and finally close an expense? BR-23 permits owner or authorized brand manager; current direct route checks BrandOwner only.
8. Which expense route is authoritative when a direct expense decision is mirrored to Dashboard operations? Specify one current revision token and atomic/idempotency key scope.
9. What replay contract is required for financial mutations (actor + tenant + route + body/revision binding, retention, conflict response)?
10. Which source-backed non-production HTTP tests and disposable fixtures will verify envelopes, tenant/branch isolation, event timing, and actual transaction boundaries before S1-05 acceptance?

## Verification blockers and next S1-01 evidence

* The original trace was static. The later six-file MySQL baseline passed, but no comprehensive endpoint-by-endpoint contract test was run. Uncovered status/envelope, event ordering, recipient acknowledgment and monetary-unit assertions remain **REQUIRES VERIFICATION**.
* Verify N3's company-route role middleware and the exact variance-allocation alias used by Dashboard; both path declarations are present at `Modules/Admin/routes/api.php:484,857`.
* Inspect full expense `submitExpense`, listeners/bridge, expense amount casts and Flutter submit call path to establish custody debit/reversal and revision effects.
* Capture Admin `run/ok` and legacy BaseController envelopes, precise presenter/resource field lists, and each exact `ShiftHandoverController` validator/transaction before a frozen S1-05 API blueprint.
* No fixes, schema changes, or compatibility changes are made by this document. S1-01 remains a discovery artifact; implementation is not started.

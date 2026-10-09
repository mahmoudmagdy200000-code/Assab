# Sprint 01 task register

## Current S1-01 delivery state — 2026-10-07 fixture correction

**Ready for review; not Accepted.** Removing one stale `map_coordinates` entry from `Modules/Branch/database/factories/BranchFactory.php` aligns the focused fixture with the final migrated Branch schema. PHP lint and all six individually run MySQL focused files passed: 41 tests, 144 assertions, zero failures/errors/blocked. The disposable test schema and original baseline each retained 283 migrations and zero persistent business rows. No infrastructure blocker remains for these S1-01 checks. Mahmoud's review is outstanding; existing test expectations alone do not settle BR/AC acceptance. S1-02, S1-03 and S1-04 are Ready for review / Not Accepted; S1-05 is Ready for Mahmoud design review / Not Accepted after the correction pass; S1-06 is Ready for review / Not Accepted under the user-authorized implementation and correction recorded below; S1-07 is an internal layer Ready for review / Not Accepted (see its row); S1-08 has two committed phases and Phase 3/4 corrections pending review, Not Accepted; S1-09–S1-15 remain Planned.

The table below is the authoritative task status. Historical failures and their resolution are in verification.md. Git commit/push state is recorded in Git history and does not imply acceptance; only Mahmoud may mark a task Accepted. S1-05 still requires the combined blueprint review before high-impact implementation.

| ID | Original task | Dependencies | Repository | Status | Evidence / limitations |
|---|---|---|---|---|---|
| S1-01 | Establish the Working Baseline and Route Map | Repository/test access | Assab; dashboard inspection; AssabAPP read-only | Ready for review; not Accepted | Eight S1-01 documents; source map, portable tools, dependencies, lint and routes verified. Dashboard typecheck has a prior recorded PASS with command/SHA not retained; a current invocation stopped before TypeScript at pnpm `EPERM` (see verification.md). Isolated MySQL 3310 baseline remains at 283/283 migrations. One-field BranchFactory correction passed PHP lint; separate disposable test schema ran six focused files: 41/41 tests and 144 assertions PASS, no persistent business rows. Mahmoud review outstanding. |
| S1-02 | Define Monetary Units and Field Meanings | S1-01 | Assab + dashboard, app reference | Ready for review; not Accepted | `money-contract.md` traces DB types/defaults/nullability/casts, legacy SAR and Admin/Dashboard halalas boundaries, conversion paths, zero/null behavior, and target cash semantics. The R04 section separates custody, unreceived-cash responsibility, provisional allocation, cashier confirmation, employee response, branch-manager final liability approval, and accountant/reporting roles as AS-IS / TO-BE / GAP. It explicitly allows report submission while receipt remains unconfirmed; BR-09 shortage-liability approval is a separate gate. Full application 115/zero/fractional/opening round-trip checks are NOT RUN; D5 calculation, precision and per-column limits are approved; integration checks remain with later tasks. No application or Dashboard code changed. |
| S1-03 | Select Minimal Schema and Compatibility Changes | S1-02 | Assab + shared compatibility | Ready for review; not Accepted | `schema-adr.md` corrects R01/R05/R07 and C03: legacy SAR-to-halalas conversion occurs once in the bridge; Admin remains integer halalas; report revision identity is owned by a stable report aggregate independent of handovers; request/receipt links remain optional and stable; end-only and native Admin close paths are covered. Duplicate/cardinality, operation identity, compatibility, and unrun migration/tests are explicitly gated/deferred. No code, migration, or DB changes. Review/acceptance outstanding. |
| S1-04 | Define State, Permissions, and Revision Invariants | S1-01–S1-03 | Assab + dashboard | Ready for review; not Accepted | `state-permission-revision.md` maps source-backed AS-IS states, actors and scopes to BR-05–10,11–17,24–25 TO-BE invariants; documents revision/history expectations and the absent daily-submit shortage-liability guard. R02/R05 remain PARTIALLY RESOLVED carry-forward. Documentation only; no runtime enforcement, migration, or acceptance test was run. |
| S1-05 | Finalize the API Blueprint and Design Review | S1-01–S1-04 | Assab + dashboard | Ready for Mahmoud design review / Not Accepted | `api-contract.md` contains one proposed technical model, concrete target API examples, liability actor routes, writer map and session blueprint. D5 rounding/precision/limits are approved; receipt-effect identity and pre-open destination remain **proposed for Mahmoud approval**. Documentation-only verification is in `verification.md`; no runtime behavior is claimed. Mahmoud acceptance is mandatory before S1-06. |
| S1-06 | Correct and Unify Shift Calculations | S1-05 blueprint review completed | Assab | Ready for review / Not Accepted | Mahmoud approved D5; S1-06 precision validation, per-column limits, half-up net and residual VAT implemented. Audit 2026-10-08 corrections (`dc73ab29` + follow-up): AssabAPP representation-noise compatibility (N-01; D10 pending Mahmoud confirmation), stored-split reads and matching stored manager split (N-02/N-03), reassign-with-handover VAT; focused 62 tests / 389 assertions. Lifecycle integration remains with later assigned tasks. |
| S1-07 | Enforce Shortage Allocation and Branch Surplus | S1-04–S1-06 | Assab | **Internal layer closed — Ready for Mahmoud review / Not Accepted.** Route integration re-sequenced to S1-08/S1-10/S1-11 (Mahmoud decision 2026-10-08) | APPROVE A internal layer (`7fd1d15c`): additive allocation/share snapshots, typed in-branch actors, cashier confirmation, separate employee response and manager approval, explicit self-share, stale-version rejection, fail-closed evidence. Completed 2026-10-08: stale-liability readiness fix (`a946b1f4`); sales-channel check separate from the cash variance (`ShiftFinancialCalculator::salesChannelCheck`); no allocation record for balanced/surplus (audit A1); daily-submit lock per (report, workday) with reasoned reopen release (`shift_liability_daily_locks`); real-schema test of FIN-02/03/04/05/10/11 at the internal-layer level. No HTTP route uses it yet; see `s1-07-implementation-handoff.md` "Completion" for the integration owners. |
| S1-08 | Make Essential Financial Writes Atomic | S1-05–S1-07 | Assab | **Accepted by Mahmoud at `8ddb90a2`.** Exact recipient-confirmed receipt and required financial effects are atomic; manager-addressed handovers require the uniquely assigned active manager (D12). Open addressed handovers block manager deactivation/transfer/delete with `409 MANAGER_HAS_OPEN_HANDOVERS`; recipient discovery remains `200` with valid cashier choices for zero/multiple managers; correction/rejection is restricted to the exact addressed manager with `403 ONLY_ADDRESSED_RECIPIENT`. S8-05 is resolved: manager cash-out is `Handover to Cashier` with receiving `cashier_name`, funded from personal sales cash less pending requests; cashier side stays `Handover Received`, with no migration. AssabAPP D4 must display `تسليم نقدية لكاشير` for this English API value and handle the new response/error codes. S8-09 is approved as a documentation/release gate, with no legacy shortage coupling; S8-12 is approved drain-before-deploy. MySQL locking validation remains open. See `s1-08-implementation-handoff.md` and `s1-08-deployment-readiness.md`. |
| S1-09 | Prevent Duplicate Effects and Unsafe Replay | S1-05,S1-08 | Assab + dashboard intent contract | **Development scope closed — proceed to S1-10 (Mahmoud instruction 2026-10-09). Production release remains gated.** | Same-commit replay for covered mobile commands and all Admin shift-close aliases; empty-body and mutable-scope fixes verified; commit-then-throw recovery fails closed. Focused 83 tests / 507 assertions PASS (SQLite). No full-suite or MySQL concurrency claim. See verification.md → S1-09 bounded closure for retained compatibility/release boundaries. |
| S1-10 | Implement Monetary Adapters and Verify App Compatibility | S1-02,S1-03,S1-06 | Assab + dashboard, app read-only | **Phase 1: Accepted by Mahmoud (2026-10-09) (audit follow-ups S10-01/02/03 closed in `4f6cf5b9`). Phase 2: D6 and corrections D14–D19 implemented (`d131fd72`…`776df28d`); ready for Mahmoud's review (not accepted). MySQL locking/concurrency NOT RUN (deploy gate); S1-10 overall remains in progress.** | Phase 2 adds the independent `counted_cash` (required, D4), immutable per-revision `shift_report_cash_counts` (integer halalas; no row = unavailable), D11 `shift_transfer_rejection_evidence`, server-derived confirmed opening and pending incoming, atomic report + count + shortage allocation on `end`, `end-with-handover` and `reassign-with-handover` (S1-09 protected), the real report-part `LiabilityEvidenceSource`, and the Admin projection from the real count (details and the D6 implementation in `verification.md` → “S1-10 Phase 2”). D6 adds the `cash_reconciliation` response object and Admin `cashCountState`. Not done: cashier shortage ledger entry, correction history, manager/employee approval routes, daily close/reopen (S1-11), AssabAPP release, MySQL validation. |
| S1-11 | Preserve Corrections, Confirmed Handovers, and Opening Evidence | S1-04,S1-05,S1-08; coordinate S1-09; **first dependency R5 versioned accountant correction** | Assab | Planned — NOT STARTED | Approved carryovers: R5 accountant report/allocation correction except gross, preserving revisions/audit, invalidating prior manager approval and requiring new approval, atomic balance deltas/idempotency/notifications; R4b cash-request cancel/replacement; R6 immutable-original linked adjustments. See `s1-10-approved-business-rules-addendum.md`. Acceptance must prove a cashier self-declared shortage posts **exactly one** cashier personal-ledger movement only after final branch-manager liability approval; receipt confirmation itself posts no shortage. No duplicate posting. Also owns daily-submit/reopen and full report correction/history orchestration. No release before this acceptance. |
| S1-12 | Correct Dashboard Backend Session Lifecycle | S1-05 auth blueprint | Assab | Planned | No session change |
| S1-13 | Correct Dashboard Refresh and Financial Retry | S1-09,S1-12 | dashboard | Planned | No retry/session change |
| S1-14 | Deliver Real Dashboard Shift Data and Correct Numbers | Per D7, pending Mahmoud decision if authoritative plan still requires Flutter refresh | Assab + dashboard | PROPOSED / BLOCKED PENDING MAHMOUD DECISION | No legacy refresh contract; see C-7 and S1-12 design item |
| S1-15 | Verify and Deliver the Complete Sprint | All preceding required tasks, explicit unresolved blockers | Assab + dashboard | Planned | This verification.md is baseline evidence, not sprint delivery |

Task status is not inferred from commits alone. Future entries must link changed files, checks, relevant SHAs and known limitations. Mahmoud alone marks Accepted. Week-two/week-three/full expense behavior remains outside this run; retain requirements without claiming them delivered.

## Separate backlog finding — Purchase test-data seeder

`Modules/Purchase/database/seeders/PurchaseTestDataSeeder.php:93,104,115,126,137` still writes the removed `branches.map_coordinates` field. This seeder is not used by the six focused tests. It was neither run nor modified; review its fixture/schema alignment in a separately authorized task before using it. This is not a new Sprint task ID and does not start S1-02.

## Requirement traceability

The original A01–A18 matrix, BR-01–BR-25 titles, and AC-01–AC-21 matrix are reproduced below from the authoritative project-docs. They are requirements, **NOT RUN**, not baseline PASS results. They retain their own source namespaces: A01 is not a replacement/renaming of AC-01. See business-rule document for the complete normative text and scope, including expense requirements deferred to later weeks. Historical A/AC traceability IDs are not v2.0 acceptance IDs; D1 mapping is recorded in the final handoff addendum below.


### Sprint acceptance: A01–A18

| ID | Scenario | Expected outcome | Tasks |
|---|---|---|---|
| A01 | Gross115/cards50/apps25/opening10/count30 | Net100,VAT15,expected50,variance−20; matching persisted/API/dashboard values | 06,10,14 |
| A02 | Expected35/count40 | Branch surplus5; no employee charge/extra approval/increased sales | 06,07,14 |
| A03 | Shortage20 split12+8 explicitly confirmed | Allowed provisional allocation; manager final approval distinct | 07 |
| A04 | Missing confirmation, shares19/21, negative share, wrong branch | Reject without financial partial writes | 07,08 |
| A05 | Manager assigned a share / unapproved shortage at daily-submit | Manager identity allowed; approval cannot be bypassed | 07 |
| A06 | Submit before recipient receipt | Report submitted, sender still responsible; dashboard does not show receipt complete | 08,11,14 |
| A07 | Reject1000→correct950→confirm | Corrected request/revision governs receipt; prior evidence retained | 11 |
| A08 | Intentionally partial request confirmed in full→report correction; third rejection | Confirmed movement unchanged; remainder with sender; correction still possible | 11 |
| A09 | Reject6150→correct/resubmit6100→confirm6100; no receipt → opening 0; settings never count as receipt; confirmedzero | New opening6100, prior50 stays sender; settings not proof; zero preserved | 10,11 |
| A10 | Ledger/audit write fails | Required financial/report writes roll back together | 08 |
| A11 | Concurrent duplicate intent, lost response, expired cache | One financial effect; recoverable result without reposting | 09,13 |
| A12 | Same key/different payload or actor; outside company/branch | Safe conflict/isolation; no response leakage or unauthorized writes | 04,09 |
| A13 | Duplicate bridge/event; monetary round-trip | No duplicate operation/counting;115 remains115 economically | 08,10,14 |
| A14 | Expired/revoked/wrong-type refresh; concurrent rotate; logout | Contract-compliant rejection/atomic rotation/revocation | 12 |
| A15 | Burst401, transient refresh failure, logout during refresh | Single-flight within promised scope; no transient auto-logout or resurrection | 13 |
| A16 | Financial retry after token refresh or response loss | Same intent/key; no duplicate effect | 09,13 |
| A17 | Existing branch expense-custody movement | Shift expected cash unchanged; full expense lifecycle deferred | 06 |
| A18 | Real dashboard data, loading/error/empty/filter/cache behavior | Correct current values; no fabricated totals or state conflation | 14 |

### Business rules: BR-01–BR-25

| ID | Original title |
|---|---|
| BR-01 | VAT-Inclusive Sales |
| BR-02 | Delivery-App Sales Before Commission |
| BR-03 | Cash Calculation and Variance Sign |
| BR-04 | Expense Source Does Not Affect Shift Expected Cash |
| BR-05 | Submit Closes the Report, Not Cash Responsibility |
| BR-06 | Three Independent Business Facts |
| BR-07 | Default Cashier Name Is Provisional |
| BR-08 | Allocate the Entire Shortage Within the Branch |
| BR-09 | Branch Manager Approves Final Liability |
| BR-10 | Record Employee Acceptance or Objection |
| BR-11 | Surplus Belongs to the Branch |
| BR-12 | Opening Cash Comes From Confirmed Receipt |
| BR-13 | Requested 1,000, Actual 950 |
| BR-14 | New Shift Receives 6,100 Instead of 6,150 |
| BR-15 | All Report Data Can Be Corrected After Rejection |
| BR-16 | Previously Confirmed Handovers Are Immutable |
| BR-17 | No Fixed Rejection Limit or Recipient Override |
| BR-18 | Debit Once on Expense Submit |
| BR-19 | Reverse Once on Rejection |
| BR-20 | External Recovery Does Not Create Another Credit |
| BR-21 | Sufficient Custody Is Required |
| BR-22 | Correct the Same Request, Start a New Review Cycle |
| BR-23 | Authorized First Decision on the Current Revision |
| BR-24 | Preserve Evidence and Previous Versions |
| BR-25 | Atomicity and Duplicate-Effect Prevention |

### Business acceptance: AC-01–AC-21

| ID | Scenario | Required outcome | Rules |
|---|---|---|---|
| AC-01 | Gross 115/cards 50/apps 25/opening 10/counted 30 | Net 100,VAT15,expected 50,variance−20,shortage 20 | BR-01–03 |
| AC-02 | Shortage 20 without confirmed allocation, or shares totaling 15 | Prevent report closure | BR-07–08 |
| AC-03 | Shortage 20 allocated 12+8 within the branch | Allocation allowed; manager approval required before daily submission | BR-08–09 |
| AC-04 | Shortage assigned to the branch manager | Manager also approves liability before daily submission | BR-09 |
| AC-05 | Employee objects to allocated shortage | Record objection; do not block handover; manager decides | BR-10 |
| AC-06 | Expected 35/counted 40 | Branch surplus 5; no employee, extra approval, or increase to sales | BR-11 |
| AC-07 | Submit while receipt remains unconfirmed | Report closed; sender retains cash responsibility | BR-05–06 |
| AC-08 | Requested 1,000/actual 950 | Reject→recount→correct→resubmit 950→confirm; allocate established shortage 50 | BR-13 |
| AC-09 | Valid partial handover is confirmed | Transfer only the confirmed amount; sender retains remainder | BR-05,12 |
| AC-10 | Correct a report with an earlier confirmed handover | Preserve all revisions/evidence; confirmed handover stays unchanged | BR-15–16,24 |
| AC-11 | Two or more handover rejections | Correction/resubmission remain available; no automatic escalation or override | BR-17 |
| AC-12 | Reject 6,150, correct/resubmit 6,100, then confirm 6,100 | New opening 6,100; prior 50 remains on previous shift; new cashier may start | BR-14 |
| AC-13 | Configured opening without confirmed receipt | Configuration alone is not actual received opening cash | BR-12 |
| AC-14 | Expense 200 from custody 1,000 | Submit→800; approval does not debit again; alternative rejection restores 1,000 | BR-18–19 |
| AC-15 | Rejected cash recovered outside the app | No additional credit | BR-20 |
| AC-16 | Paid expense 500 against custody 300 | Block until actual replenishment is recorded | BR-21 |
| AC-17 | Reject 200, correct same request to 150, resubmit | Preserve old cycle; deduct 150 once; balance 850 for the example | BR-22 |
| AC-18 | Concurrent decisions or a decision for an old revision | One valid decision for the current revision; no duplicate/conflicting effect | BR-23,25 |
| AC-19 | Accountant attempts expense approval | Deny approval; review permission does not grant approval authority | BR-23 and role matrix |
| AC-20 | Branch expense-custody expense occurs during a shift | Expected shift cash remains unchanged by the expense | BR-04 |
| AC-21 | Retry or failure during financial movement/audit persistence | No second effect and no partially persisted essential financial state | BR-25 |

## Mahmoud final handoff mapping and status — 2026-10-07

D1 applies: Execution Plan v2.0 is authoritative for scope, task numbering, and acceptance IDs; the Agent Implementation Plan is an execution aid. The two tables below are copied from the Project document `Assab-D1-Plan-Authority-and-D2-Opening-Question-2026-10-07.md` §2–§3 (prepared by the independent reviewer; it records Mahmoud's D1 decision), whose definitions come from the Execution Plan v2.0 acceptance table. Legacy A01–A18 and AC-01–AC-21 remain cross-reference namespaces only. **A18 Dashboard data is outside v2.0 week 1 unless Mahmoud explicitly restores it.** *(Corrected 2026-10-08, audit N-05: the previous FIN-05/FIN-06 descriptions did not match v2.0.)*

**v2.0 task mapping**

| v2.0 task | v2.0 definition (short) | Repo task / artifact | Note |
|---|---|---|---|
| S1-01 | Route → controller → service → tables map | S1-01 | Same |
| S1-02 | Money units in DB/API/UI | S1-02 | Same |
| S1-03 | Schema/compatibility decision, limited migration | S1-03 | Same |
| S1-04 | Cash sales vs counted cash vs confirmed opening; field meanings | Content in S1-02 `money-contract.md` and S1-05 §4 | Repo "S1-04 states/permissions" becomes supporting design |
| S1-05 | Request/response, errors, idempotency contract with mandated examples | S1-05 | Same |
| S1-06 | VAT from inclusive gross; cash calculation | S1-06 | Same |
| S1-07 | Channel validation separate from cash comparison; allocation; manager approval before daily submit; branch surplus | S1-07 | Same; daily-submit set (F3) lands here |
| S1-08 | Atomic report + movements + audit; transfer on confirmed receipt only | S1-08 | Same |
| S1-09 | No duplicate effect on retry/concurrency | S1-09 | Same |
| S1-10 | Apply money-unit decision across affected paths | S1-10 | Same |
| S1-11 | Company/branch boundaries; current-revision transitions; correction preserving history | S1-11 | v2.0 adds boundary tests (SEC-01); RX-02 belongs here |
| S1-12 | Backend access/refresh expiry, rotation, revocation, concurrency | S1-12 | Same scope |
| S1-13 | Dashboard refresh; keep the financial idempotency key across retries | S1-13 | Same |
| S1-14 | **Flutter refresh, tested on the real client** | — (repo S1-14 is Dashboard data) | Blocked pending AssabAPP access; repo S1-14 out of week 1 unless added |
| S1-15 | Run acceptance matrix, deliver evidence | S1-15 | Use v2.0 IDs |

**v2.0 acceptance mapping**

| v2.0 ID | Repo A-ID(s) | Note |
|---|---|---|
| FIN-01 | A01 | — |
| FIN-02 | A04 (missing confirmation) | — |
| FIN-03 | A03, A05 | — |
| FIN-04 | A02 | — |
| FIN-05 (expected = counted) | — | **Add** |
| FIN-06 (app sale 115, not 95 after commission) | — | **Add** |
| FIN-07 | A13 (round-trip) | — |
| FIN-08 (configured 50 / confirmed 10) | A09 (settings not proof) | D2 answered: opening 10; settings never feed opening |
| FIN-09 | A17 | — |
| FIN-10 (12+8 / 15 only / outside branch) | A03, A04 | — |
| FIN-11 (manager self-share; objection does not block) | A05 | Objection case not in the A-list; **add**. Full daily-submit path is week 2 per plan |
| TX-01 | A10 | — |
| TX-02 | A11, A13 (duplicate bridge) | — |
| TX-03 | A12 | — |
| TX-04 | A11, A16 | — |
| HAND-01 | A06 | — |
| HAND-02 | A09 | Full correction path is week 2 per plan |
| HIST-01 | A08, A07 (history part) | A07 full flow (AC-08) is week 2 |
| SEC-01 | A12, A04 (wrong branch) | Include RX-02 regression |
| AUTH-01 | A15, A14 (concurrent rotate) | — |
| AUTH-02 | A14 | Include probe D cases (refresh token used as access token; inactive user refreshing) |
| AUTH-03 | A15 | — |
| MOB-01 | — | **Add**; Blocked pending AssabAPP |
| — | A18 (Dashboard data) | Not in v2.0 week 1; needs a Mahmoud scope decision |

**Regression records attached to v2.0 IDs**

| Regression | Defect reproduced 2026-10-07 | v2.0 ID | Status |
|---|---|---|---|
| RX-01 | Bridge reads the sales breakdown before it is saved → Admin app total 0 → phantom shortage auto-defaulted to the cashier on final approval and carried to payroll export | TX-02, FIN-06 | Regression mapped; runtime deferred |
| RX-02 | `recordVariance` has no owner/branch check → cross-branch liability rows | SEC-01 | Regression mapped; runtime deferred |
| RX-03 | Admin `/api/v1/auth/*`: no token expiry; refresh token usable as access token; inactive user can refresh | AUTH-02 | Regression mapped; runtime deferred |
| MOB-01 | Legacy mobile token facts (C-7) and no-refresh contract | MOB-01 | D7 proposed / pending Mahmoud |
| FIN-11 | Employee objection recorded without blocking handover; manager decides liability | FIN-11 | Scenario added; runtime deferred |

**S1-08 evidence mapping:** [FIN-08 / A09](../../tests/Feature/ShiftTransferReceiptTest.php) is covered by `ShiftTransferReceiptTest::test_configured_float_is_not_opening_and_late_receipt_applies_once` (configured float is not proof; only the actual confirmed receipt changes opening). [HAND-01 / A06](../../tests/Feature/ShiftTransferReceiptTest.php) request-versus-receipt semantics are covered by `test_manager_transfer_request_has_destination_identity_and_no_receipt_until_confirmation` and `test_manager_review_does_not_approve_cashier_request_and_named_cashier_still_confirms`; full daily-submit membership remains S1-11. [TX-01 / A10](../../tests/Feature/ShiftTransferReceiptTest.php) required-write rollback is covered by `ShiftTransferReceiptTest::test_manager_ledger_failure_rolls_back_receipt_transfer_and_recipient_custody`, `ShiftTransferReceiptTest::test_manager_receipt_audit_failure_rolls_back_receipt_custody_ledger_and_state`, and [`ShiftHandoverVarianceCustodyTest::test_required_manager_receipt_ledger_failure_rolls_back_receipt_and_handover_confirmation`](../../tests/Feature/ShiftHandoverVarianceCustodyTest.php).

S1-14 is **PROPOSED / BLOCKED PENDING MAHMOUD DECISION** if the authoritative plan requires Flutter refresh without a legacy backend refresh contract. Record mobile token lifecycle design in S1-12. This is D7 proposal, not approval. S1-06 STARTED: NO.

Each future regression asserts status, amounts, and relevant row counts; `assertTrue(true)` is unacceptable. MySQL is required for locking/concurrency evidence, not SQLite in-memory. These tests were not run in this pass.

## Approved decisions synchronized — 2026-10-07

Current Sprint working-contract decisions now authorize Branch Manager direct cashier-report correction under preserved correction history, cashier notification, no cashier approval, immutable confirmed receipts, and one recalculation. Pending incoming receipt does not block shift report submission; sender responsibility, physical count and explicit pending state remain distinct, while accountant daily submission waits for required transfer completion. Excess manager transfer requests are rejected against available recorded sales-cash net of reservations/commitments, without movement or automatic shortage; unrecorded cash requires legitimate source recording, never expense custody. See `api-contract.md`, `money-contract.md`, and `state-permission-revision.md`. These decisions supersede D3/D9 pending wording in current Sprint docs; historical handoff/audit records retain their original as-of status.

## S1-06 implementation status — 2026-10-07

This current entry supersedes the historical “S1-06 was not started” status above. Published implementation/evidence commits are `6fcce32c0f921d51821de76114c193cf5343bd27` and `e8fc27577aeb75bb643e87c825bb0394424832e8`. The targeted F1–F3 round adds optional source-backed `pendingIncomingCounted` (default zero) to the integer-halalas calculator without wiring untrusted route inputs. F1/F2/F3 contract corrections remain verified; transfer, liability and daily-submit runtime work remains with later assigned tasks. Mahmoud has approved D5: two-decimal SAR maximum, HTTP 422 before calculation, actual per-column DB limits, integer-halalas arithmetic, half-up net and residual VAT. S1-06 remains **Ready for review / Not Accepted**. S1-10/S1-11 own independent count, receipt evidence and pending-transfer integration; S1-07/S1-11 own liability enforcement and S1-07 owns the daily gate. No end-to-end lifecycle acceptance is claimed. S1-07 has not started.


## Final S1-06 validation correction — 2026-10-07

Starting published HEAD f06964c18a60b2a44806fe5a8de74461d52bdaa9. Monetary request validation now rejects excess precision and out-of-column amounts with 422 on actual affected API paths before shared calculation. Existing 15 tests / 54 assertions are preserved; two calculator boundary regressions bring that suite to 17 tests / 58 assertions. Request/feature validation adds 26 tests / 234 assertions (43 tests / 292 assertions across focused runs). S1-06 is **READY FOR REVIEW / NOT ACCEPTED**. F1/F2/F3 contracts remain PASS; no end-to-end lifecycle closure claimed. **D5 APPROVED by Mahmoud**: integer halalas, two-decimal SAR maximum with excess rejected by HTTP 422 before calculation, actual per-column DB limits, half-up net and residual VAT. S1-07 STARTED: NO.


## S1-07 APPROVE A implementation — current status

The user authorized the additive schema and internal implementation. This supersedes the historical design-stop entry. The model/services and isolated persistence tests are ready for review; no full S1-07 acceptance, production deployment, real-source adapter, or legacy HTTP enforcement is claimed. The explicit integration boundaries are in `s1-07-implementation-handoff.md`. S1-08 and later tasks have not been started by this change.

The current liability readiness check treats trusted nonnegative variance as having no liability requirement even when older shortage allocations exist. Those allocations and decisions remain historical; a later shortage requires a fresh current allocation/approval. Required receipt/scope checks remain independent and in force.

## S1-08 Phase 2 implementation status — 2026-10-08

This entry supersedes earlier historical notes that S1-08 had not started. Phase 1 is committed at `9fc8ebacf99d23a1b43d27101420be65e4f5e5f0`; Phase 2 is committed at `569d00e77dea922c03782c001c3c26c2cd30cba9`. Migration `2026_10_08_000004_add_manager_recipient_receipt_fields.php` was added in Phase 2; no applied migration was rewritten. The committed work hardens cashier close, manager variance approval, and Admin/native shift final approval. Atomic independent-count report plus shortage-allocation/evidence composition is deferred to S1-10. Bridge/statistics listeners are projections after commit. Manager approval alone does not post a handover `Total Sales` ledger entry or create receipt evidence. `ShiftTransferReceiptService` remains the sole receipt writer. Phase 3/4 and the correction pass are pending Mahmoud review.

Historical comparison: the Phase 2 comparison-baseline report was compared to `c018fa01` with zero new failure/error identities despite environment and unrelated baseline failures. The earlier corrected-worktree final reports showed 73 shared bad identities, 23 Phase 2-only, and zero current-only; the eight then-current-only identities were absent. The later baseline hard-close comparison and fresh reports are recorded in `verification.md`. Pint, PHP syntax, and `git diff --check` pass. No MySQL concurrency or production DDL run has occurred. D11, D12, and D13 were approved by Mohamed on 2026-10-08. S8-05 is resolved as manager cash-out `Handover to Cashier` with no migration; S8-09 has **no S1-08 code change** and **no release before S1-11**; S8-12 uses **drain-before-deploy** with a maintenance-mode pending-handover count of zero and no normal-deployment backfill. S1-08 remains **Not Accepted** pending Mahmoud review and rollout gates. S1-09 replay, S1-10 trusted counted-cash evidence, and S1-11 public liability/full correction/daily-submit-reopen wiring remain deferred.


**S1-11 carry-overs from the S1-10 corrections (2026-10-09):** (1) the reassignment split (outgoing/incoming rows with handover request and receipt identity) replacing the `REASSIGNMENT_SPLIT_REQUIRED` fallback — first item; (2) supersede instead of hard delete in the legacy rejection paths; (3) remove the legacy two-rejection lockout; (4) manager-entered count (D3); (5) single shortage posting on branch-manager final approval with an Admin mirror (no Admin `EmployeeMovement` is created for a counted legacy shortage before then).

## S1-10 final bounded closure C1–C5 — review status (2026-10-09)

Phase 1 remains **Accepted by Mahmoud**. Phase 2 remains **not Accepted**: C1 shared counted-reassignment guard, C2 source-branch D19 attribution, C3 locked/re-read accept/reject and adjacent C4 pre-upload conflict check are prepared on `task/s1-10-final-bounded-closure` from `470669020e94f6cb1a45f3fe2e6fa49fc12a3b9d`. Mahmoud must review the eventual correction SHA. This entry does not claim publication or acceptance. MySQL concurrency = **NOT_RUN**; AssabAPP and Dashboard integration/release gates stay open. No S1-11 split or client implementation is included. Fresh developer-run evidence is recorded in `verification.md`.

### S1-10 approved bounded correction round — 2026-10-09

User authorized A1/R2a/R2b/C1 independent incoming work/report ownership using existing rows, R1 reminder-only timeout, and C4 failed-attempt upload cleanup; preserve C2/C3. R3b is investigation only. Old D15 blanket accountant restriction is business-superseded, runtime retained temporarily; R5 is first S1-11 dependency, with R4b/R6 carryovers. See the approved addendum and fresh verification evidence. **Phase 2 NOT ACCEPTED until Mahmoud review. S1-11 NOT STARTED. MYSQL CONCURRENCY = NOT_RUN.** No AssabAPP/Dashboard changes or deployment authorization.
# Sprint 01 task register

## Current S1-01 delivery state — 2026-10-07 fixture correction

**Ready for review; not Accepted.** Removing one stale `map_coordinates` entry from `Modules/Branch/database/factories/BranchFactory.php` aligns the focused fixture with the final migrated Branch schema. PHP lint and all six individually run MySQL focused files passed: 41 tests, 144 assertions, zero failures/errors/blocked. The disposable test schema and original baseline each retained 283 migrations and zero persistent business rows. No infrastructure blocker remains for these S1-01 checks. Mahmoud's review is outstanding; existing test expectations alone do not settle BR/AC acceptance. S1-02–S1-15 remain Planned. No commit or push.

The table below is the authoritative current status. Historical failures and their resolution are in verification.md. No files are staged, committed or pushed; only Mahmoud may mark a task Accepted. S1-05 still requires the combined blueprint review before high-impact implementation.

| ID | Original task | Dependencies | Repository | Status | Evidence / limitations |
|---|---|---|---|---|---|
| S1-01 | Establish the Working Baseline and Route Map | Repository/test access | Assab; dashboard inspection; AssabAPP read-only | Ready for review; not Accepted | Eight S1-01 documents; source map, portable tools, dependencies, lint, routes and Dashboard typecheck verified. Isolated MySQL 3310 baseline remains at 283/283 migrations. One-field BranchFactory correction passed PHP lint; separate disposable test schema ran six focused files: 41/41 tests and 144 assertions PASS, no persistent business rows. Mahmoud review outstanding. |
| S1-02 | Define Monetary Units and Field Meanings | S1-01 | Assab + dashboard, app reference | Planned | No money-contract.md produced |
| S1-03 | Select Minimal Schema and Compatibility Changes | S1-02 | Assab + shared compatibility | Planned | No schema/ADR decision or migration |
| S1-04 | Define State, Permissions, and Revision Invariants | S1-01–S1-03 | Assab + dashboard | Planned | No proposed state/permission design |
| S1-05 | Finalize the API Blueprint and Design Review | S1-01–S1-04 | Assab + dashboard | Planned | Mandatory review before implementation |
| S1-06 | Correct and Unify Shift Calculations | Accepted S1-05 blueprint | Assab | Planned | Existing calculation differences recorded only |
| S1-07 | Enforce Shortage Allocation and Branch Surplus | S1-04–S1-06 | Assab | Planned | Existing allocation/approval differences recorded only |
| S1-08 | Make Essential Financial Writes Atomic | S1-05–S1-07 | Assab | Planned | Existing boundaries recorded only |
| S1-09 | Prevent Duplicate Effects and Unsafe Replay | S1-05,S1-08 | Assab + dashboard intent contract | Planned | No idempotency change/concurrency execution |
| S1-10 | Implement Monetary Adapters and Verify App Compatibility | S1-02,S1-03,S1-06 | Assab + dashboard, app read-only | Planned | No adapter/client edits |
| S1-11 | Preserve Corrections, Confirmed Handovers, and Opening Evidence | S1-04,S1-05,S1-08; coordinate S1-09 | Assab | Planned | Destructive/fixed-count paths discovered, not fixed |
| S1-12 | Correct Dashboard Backend Session Lifecycle | S1-05 auth blueprint | Assab | Planned | No session change |
| S1-13 | Correct Dashboard Refresh and Financial Retry | S1-09,S1-12 | dashboard | Planned | No retry/session change |
| S1-14 | Deliver Real Dashboard Shift Data and Correct Numbers | S1-06–S1-11 + dashboard API contract; verify sessions with S1-13 | Assab + dashboard | Planned | Hook/fixture inventory only |
| S1-15 | Verify and Deliver the Complete Sprint | All preceding required tasks, explicit unresolved blockers | Assab + dashboard | Planned | This verification.md is baseline evidence, not sprint delivery |

For every row: implementation/documentation commit SHA = none this run; pushed = no; PR = none. Future entries must link actual changed files, checks, final SHAs and known limitations. Mahmoud alone marks Accepted. Week-two/week-three/full expense behavior remains outside this run; retain requirements without claiming them delivered.

## Separate backlog finding — Purchase test-data seeder

`Modules/Purchase/database/seeders/PurchaseTestDataSeeder.php:93,104,115,126,137` still writes the removed `branches.map_coordinates` field. This seeder is not used by the six focused tests. It was neither run nor modified; review its fixture/schema alignment in a separately authorized task before using it. This is not a new Sprint task ID and does not start S1-02.

## Requirement traceability

The original A01–A18 matrix, BR-01–BR-25 titles, and AC-01–AC-21 matrix are reproduced below from the authoritative project-docs. They are requirements, **NOT RUN**, not baseline PASS results. They retain their own source namespaces: A01 is not a replacement/renaming of AC-01. See business-rule document for the complete normative text and scope, including expense requirements deferred to later weeks. No new acceptance/task identifiers are introduced.


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
| A08 | Confirmed partial receipt→report correction; third rejection | Confirmed movement unchanged; remainder with sender; correction still possible | 11 |
| A09 | Confirm6100 after request6150; opening from settings only; confirmedzero | New opening6100, prior50 not on new cashier; settings not proof; zero preserved | 10,11 |
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
| AC-12 | Confirm 6,100 after earlier request 6,150 | New opening 6,100; prior 50 remains on previous shift; new cashier may start | BR-14 |
| AC-13 | Configured opening without confirmed receipt | Configuration alone is not actual received opening cash | BR-12 |
| AC-14 | Expense 200 from custody 1,000 | Submit→800; approval does not debit again; alternative rejection restores 1,000 | BR-18–19 |
| AC-15 | Rejected cash recovered outside the app | No additional credit | BR-20 |
| AC-16 | Paid expense 500 against custody 300 | Block until actual replenishment is recorded | BR-21 |
| AC-17 | Reject 200, correct same request to 150, resubmit | Preserve old cycle; deduct 150 once; balance 850 for the example | BR-22 |
| AC-18 | Concurrent decisions or a decision for an old revision | One valid decision for the current revision; no duplicate/conflicting effect | BR-23,25 |
| AC-19 | Accountant attempts expense approval | Deny approval; review permission does not grant approval authority | BR-23 and role matrix |
| AC-20 | Branch expense-custody expense occurs during a shift | Expected shift cash remains unchanged by the expense | BR-04 |
| AC-21 | Retry or failure during financial movement/audit persistence | No second effect and no partially persisted essential financial state | BR-25 |

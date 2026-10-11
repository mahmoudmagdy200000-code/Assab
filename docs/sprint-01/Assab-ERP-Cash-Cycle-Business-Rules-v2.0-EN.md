# Assab ERP Cash Cycle — Business Rules v2.0

> Current S1-11 decisions and sequencing: §19 supersedes stale S1-11 scheduling/NOT STARTED statements below. Historical S1-10 acceptance and historical test results remain unchanged.

**Business-rule version:** 2.0
**Edition:** English Markdown for developers and implementation agents
**Source date:** 2026-10-06
**Last updated:** 2026-10-11 — Mahmoud S1-11 corrective decisions and continuation authorization; see §19

**Source:** The supplied Arabic v 2.0 PDF, Mohamed's approved answers and subsequent approved clarifications recorded in the project conversation, Mahmoud's final S1-10 acceptance decision, and his dated S1-11 audit/correction instructions recorded in §19.

**Technical lead and acceptance owner:** Mahmoud
**Business-rule authority:** Mohamed
**Status:** Approved business requirements. S1-10 is **ACCEPTED BY MAHMOUD / CLOSED** at code SHA `29b5c208055c7db34d8cb9826778dbe256cca204`; implementation/evidence scope and open release gates are recorded separately in §17. This acceptance does not mean that every rule in this document is implemented or production-ready.

**Current clarification:** The approved 2026-10-09 rules in §16 govern D15/D16/D19, operational responsibility, physical attempts/returns and accountant approval invalidation, and supersede conflicting earlier interpretations. §17 records Mahmoud's accepted implementation baseline and S1-11 carryovers. Version 2.0 and the existing BR/AC identifiers are retained. Receipt confirmation is not a prerequisite for incoming work start. Current S1-11 corrective contracts and continuation authorization are recorded in §19; unresolved authority and acceptance boundaries remain explicit.

**Implementation history:** §18 records the available S1-01–S1-09 delivery details and test outcomes, subsequent S1-10 implementation, and the remaining S1-11–S1-15 scope. Historical stage results do not independently establish current production readiness or acceptance of another stage.

## 1. Authority and Use

This document translates and structures the approved rules for implementation and acceptance testing. It preserves business-rule identifiers BR-01–BR-25 and acceptance identifiers AC-01–AC-21 used in the related project documents.

Version 2.0 supersedes conflicting provisions in version 1.1. Earlier VAT and delivery-app sales rules remain effective. Existing code, historical documentation, or old test expectations must not override Mohamed's approved behavior.

- **MUST / MUST NOT:** Mandatory requirements derived from the approved rules.
- **Business rules:** Define the required outcome.
- **Code, migrations, and executed tests:** Establish current behavior and the implementation gap.
- **Sprint plans:** Define scheduling and assigned scope. This reference does not require every rule to be implemented in the same sprint.
- **Technical design:** Mahmoud reviews representation, compatibility, and implementation decisions; conceptual names in this document are not mandatory database columns, enums, or API field names.

Normative rules and acceptance cases define requirements, not blanket implementation/test claims. Only the explicitly scoped acceptance and recorded evidence in §17 establish the accepted S1-10 implementation status.

## 2. Shared Vocabulary

| Term | Meaning |
|---|---|
| Gross sales | Sales including VAT, as reported by the POS closing report |
| Net sales | Sales before VAT, derived from gross sales |
| Delivery-app sales | Customer sale value before application commission |
| Counted cash | Cash physically counted by the cashier; independent of expected cash and requested handover |
| Confirmed opening cash | Cash actually received and confirmed for the shift |
| Expected cash | Server-calculated cash amount under the approved cash equation |
| Variance | Counted cash minus expected cash; negative means shortage, positive means surplus |
| Shortage allocation | The complete assignment of shortage responsibility to branch employees |
| Final liability approval | The branch manager's approval of shortage responsibility before daily submission |
| Handover request | The amount and recipient recorded by the sender for confirmation |
| Confirmed handover | Receipt explicitly confirmed by the recipient for the correct request |
| Remaining cash responsibility | Responsibility retained by the sender for cash not confirmed as received |
| Branch expense custody | The expense fund for which the branch manager is responsible; distinct from cashier sales cash |
| Report revision | A preserved version of the shift report before/after a correction |
| Expense review cycle | One submission and review attempt for the same expense request |

All numerical examples are in **Saudi riyals (SAR)**. The actual DB/API/UI unit must be documented during implementation. Examples are business values, not ready-made endpoint payloads.

## 3. Sales, VAT, and Expected Cash

### BR-01 — VAT-Inclusive Sales

The sales amount entered by the cashier MUST be the VAT-inclusive sales value shown in the POS closing report.

```text
net_sales = gross_sales / 1.15
vat_amount = gross_sales - net_sales
```

VAT is extracted information. It MUST NOT be deducted again when calculating expected cash. Applying `gross_sales * 0.15` as the VAT contained in a VAT-inclusive amount does not implement this rule.

### BR-02 — Delivery-App Sales Before Commission

Delivery-app sales MUST use the customer's sale value before commission. A customer sale of SAR115 MUST be recorded as 115, not 95 received after commission.

Commission belongs to settlement of the delivery application's account, outside the shift cycle. It MUST NOT change the shift cash calculation.

### BR-03 — Cash Calculation and Variance Sign

When there are no other cash movements, the system MUST calculate:

```text
expected_cash = gross_sales - card_sales - delivery_app_sales + confirmed_opening_cash
reconciled_counted = counted_cash - pendingIncomingCounted
variance = reconciled_counted - expected_cash

variance < 0: shortage_amount = -variance
variance > 0: surplus_amount = variance
variance = 0: balanced
```

Counted cash MUST remain an independent input. It MUST NOT be forced to equal expected cash or derived from the sales-channel breakdown.

`pendingIncomingCounted` is pending incoming transfer cash physically present in the drawer and already included in counted cash, backed by a link to its specific transfer/handover, physical delivery attempt where available, and receiving shift. It defaults to zero, is nonnegative and cannot exceed counted cash. A pending request alone is not evidence of physical presence. Sender responsibility remains until receipt confirmation. For a rejected physical attempt, subtract only evidenced returns confirmed by the original sender; return initiation alone does not reduce retained cash. On receipt confirmation, remove the same amount from pending classification and add it once to confirmed opening; do not include the same transfer in both classifications. A physical return after a report count requires a fresh count/revision under §16.3, not rewriting the historical count.

For gross115/cards50/apps25: (A) opening0/count50/pendingIncomingCounted10 gives expected40/reconciled40/variance0/shortage0/surplus0; (B) after confirming that same10, opening10/count50/pendingIncomingCounted0 gives expected50/reconciled50/variance0; (C) a request10 with no physical arrival contributes nothing to count or pendingIncomingCounted, so opening0/count40/pendingIncomingCounted0 remains balanced. All amounts are SAR. These clarify existing ownership rules without introducing a lifecycle state or schema.

### BR-04 — Expense Source Does Not Affect Shift Expected Cash

Cash expenses MUST come only from branch expense custody, not the cashier drawer or sales cash. A branch expense-custody expense MUST NOT be deducted from expected shift cash.

### Approved Calculation Example

| Input or result | SAR |
|---|---:|
| Gross sales including VAT | 115 |
| Card sales | 50 |
| Delivery-app sales | 25 |
| Confirmed opening cash | 10 |
| Counted cash | 30 |
| Net sales:115 / 1.15 | 100 |
| VAT:115 − 100 | 15 |
| Expected cash:115 − 50 − 25 + 10 | 50 |
| Variance:30 − 50 | -20 |
| Shortage requiring allocation | 20 |

## 4. Report Submission and Cash Responsibility

### BR-05 — Submit Closes the Report, Not Cash Responsibility

Submit MUST close the cashier's shift report even when an incoming transfer from a branch manager or previous cashier remains pending confirmation or rejection. A pending incoming transfer MUST NOT block shift report submission. Until confirmation, responsibility MUST remain with the sender and receipt status MUST remain explicitly unconfirmed. If physical cash is already in the recipient's drawer, it MUST be included in counted cash; retain the unconfirmed-receipt state and do not classify it as branch surplus solely because confirmation is pending.

A partial confirmed handover transfers responsibility only for the confirmed amount. Responsibility for the unreceived remainder MUST stay with the sender.

### BR-06 — Distinct Report, Receipt, Daily Submission, and Liability Facts

The implementation MUST distinguish:

1. Shift report submission.
2. Recipient confirmation of cash receipt.
3. Daily submission/sending the day to the accountant.
4. Final approval of shortage responsibility.

Completing any one MUST NOT imply that another has occurred. Shift report submission is allowed with a pending incoming transfer; daily submission to the accountant waits for completion of the required handovers/transfers under the daily-close rules. Data representation is a technical design decision reviewed by Mahmoud.

## 5. Shortages, Allocation, and Surpluses

### BR-07 — Default Cashier Name Is Provisional

The shift cashier's name may appear by default to simplify allocation. The cashier may change the responsible employee or split the shortage.

The default name MUST NOT constitute final liability. For original cashier submission, the cashier MUST explicitly confirm the allocation before report closure. A Branch Manager correction and its resulting allocation follow BR-15/§15 without requiring cashier re-approval; full in-branch allocation and manager final liability approval still apply.

### BR-08 — Allocate the Entire Shortage Within the Branch

A shortage MUST be allocated to one or more employees within the branch. The sum of assigned shares MUST equal the entire shortage before report closure.

No portion may remain without a responsible employee. An external cause or explanation does not replace complete allocation.

### BR-09 — Branch Manager Approves Final Liability

The branch manager MUST approve final shortage responsibility before daily submission. This requirement also applies when the shortage is assigned to the branch manager personally. Daily submission to the accountant MUST also wait until the handovers/transfers required by the approved daily-close rules are complete.

A later accounting approval MUST NOT replace this decision with an automatic final allocation to the cashier.

If the accountant changes report data or shortage distribution after branch-manager approval, the new revision MUST invalidate that approval for the current report. Preserve the previous revision and approval as history; require a new branch-manager approval before sending the day, including when the correction removes the shortage. See §16.5 for permissions, balances, audit, notifications and duplicate-charge protection; the complete workflow remains an S1-11 carryover.

### BR-10 — Record Employee Acceptance or Objection

The affected employee MUST be informed, and their acceptance or objection MUST be recorded. The final decision belongs to the branch manager.

An employee's objection MUST NOT block cash handover. Assigning shortage responsibility does not authorize an automatic payroll deduction.

### BR-11 — Surplus Belongs to the Branch

A positive cash variance MUST be recorded as branch cash surplus:

- No employee responsibility is required.
- No additional approval is required for recording the surplus.
- The accountant reviews it as part of the day.
- It MUST NOT automatically increase shift sales.
- Pending, unconfirmed incoming cash MUST NOT be classified as branch surplus solely because recipient confirmation is outstanding. If physically in the drawer, include it in counted cash while preserving explicit unconfirmed receipt state.

Example: expected 35 and counted 40 produce a branch surplus of 5; gross sales remain unchanged.

## 6. Handovers, Opening Cash, and Amount Corrections

### BR-12 — Opening Cash Comes From Confirmed Receipt

The sender records the amount; the recipient confirms actual receipt. Cash responsibility MUST transfer only for the confirmed handover amount.

That confirmed amount becomes the receiving shift's opening cash. A configured shift opening amount alone MUST NOT be treated as evidence of actual receipt. A cashier MAY submit the shift report before an incoming transfer is confirmed; see BR-05. Shift report submission does not confirm the receipt.

### Manager-Funded Cashier Transfer Limit (approved clarification; no new BR identifier)

A Branch Manager MUST NOT register a cashier transfer for more than available recorded sales-cash after amounts reserved or committed by pending transfers. Reject an excessive request with no financial effect and no automatic shortage. Physical cash not represented in the system MUST first be recorded through its legitimate source. Branch expense custody MUST NOT fund a cashier transfer.

### BR-13 — Requested 1,000, Actual 950

The required sequence is:

1. Recipient rejects the 1,000 request.
2. Both parties recount.
3. Sender corrects the data and submits a 950 request.
4. Recipient confirms 950.
5. If a 50 shortage is established, record it with a specified responsible employee under the shortage rules, without leaving an unresolved handover dispute.

The recipient MUST NOT confirm an unchanged 1,000 request as 950. Correction and resubmission precede confirmation of the correct amount.

### BR-14 — New Shift Receives 6,100 Instead of 6,150

The new cashier MAY start work independently of this request, the predecessor's report and receipt confirmation. Correct the request to 6,100 and obtain recipient confirmation; only then does 6,100 become confirmed opening cash. If cash must be physically presented again after a return, record a new attempt under §16.3.

The 50 difference MUST be handled on the preceding shift. It MUST NOT be assigned to the incoming cashier or prevent that cashier from starting work. Reassignment alone does not transfer operational responsibility; the replacement's actual start does, without closing the predecessor's financial report.

## 7. Rejection, Report Corrections, and History

### BR-15 — All Report Data Can Be Corrected After Rejection

Handover rejection MUST permit the cashier to correct all shift-report data:

- Sales and payment channels.
- Counted cash.
- Handover and remaining amounts.
- Shortage reason and responsible employee(s).

The cashier may correct and resubmit the report after rejection, and a Branch Manager may also directly correct cashier report figures, including sales and payment-channel figures. Cashier approval is NOT required before daily submission after a Branch Manager correction. For every correction, the system MUST preserve the old value, new value, correction reason, actor, and timestamp, and notify the cashier. The system MUST recalculate expected cash and variance automatically exactly once for the correction. Any resulting shortage MUST be created once only; the correction record itself MUST NOT duplicate the shortage.

### BR-16 — Previously Confirmed Handovers Are Immutable

Any previously confirmed handover MUST remain preserved and unchanged by report corrections.

The system MUST NOT delete a confirmed movement or transfer its responsibility again when the report is resubmitted or directly corrected by a Branch Manager. Permission to correct report data does not permit rewriting an established receipt. Confirmed handovers/receipts remain immutable under all report corrections.

### BR-17 — No Fixed Rejection Limit or Recipient Override

The system MUST NOT permanently close the process after a fixed number of rejections or automatically escalate it to the owner.

Correction and resubmission remain available until the recipient confirms the corrected request. A manager MUST NOT override recipient rejection.

## 8. Branch Expense Custody and Review Cycles

### BR-18 — Debit Once on Expense Submit

A paid expense funded from branch expense custody MUST reduce custody balance once when submitted for review.

Approval confirms the existing deduction; it MUST NOT deduct the amount again.

### BR-19 — Reverse Once on Rejection

Rejection MUST reverse the deduction once and restore the amount to the manager's recorded custody balance. The manager remains responsible for it.

Restored ledger balance does not mean that physical cash has already been recovered at the moment of rejection.

### BR-20 — External Recovery Does Not Create Another Credit

The manager recovers the rejected expense amount outside the application. Recovery MUST NOT add the amount to the balance again because rejection already restored it.

The rejected expense, rejection reason, and reversal movement MUST remain preserved.

### BR-21 — Sufficient Custody Is Required

The system MUST prevent submission of a paid expense funded from custody when the custody balance is insufficient.

Example: custody 300 and expense 500 → block submission until an actual replenishment has been recorded. Do not create fictitious replenishment or use cashier sales cash to cover the expense.

### BR-22 — Correct the Same Request, Start a New Review Cycle

After correction, a new review cycle MUST start for the same expense request. Preserve the preceding cycle completely.

The corrected amount MUST be deducted once on resubmission, because the preceding deduction was reversed on rejection.

### BR-23 — Authorized First Decision on the Current Revision

Expense approval belongs to the owner OR the brand manager according to permissions. Approval by one authorized party is sufficient; the accountant is not an additional expense approver.

The first authorized decision on the current revision MUST take effect. Prevent duplicate or conflicting decisions, including simultaneous requests. A decision targeting an old revision MUST NOT affect a new review cycle.

### Approved Expense Examples

Approval and rejection below are alternative paths for the same cycle, not sequential decisions on that cycle.

| Action | Custody balance, SAR |
|---|---:|
| Initial custody | 1,000 |
| Submit paid expense 200 | 800 |
| Approve that expense | Remains 800 |
| Alternatively, reject that expense | Returns to 1,000 |
| Recover the rejected cash outside the app | Remains 1,000 |
| Correct the rejected request to 150 and resubmit | 850 |
| Approve the new cycle | Remains 850 |

## 9. Roles and Responsibilities

These are business responsibilities. Match them to actual application identities and permissions; this table does not prescribe new database role names.

| Role | Approved responsibility |
|---|---|
| Cashier | Enter the report, explicitly confirm complete shortage allocation before closure, correct rejected handovers, and remain responsible for unreceived cash |
| Recipient | Count cash and confirm the exact handover/physical attempt or reject it; initiate a physical return, but cannot confirm the return on the original sender's behalf |
| Employee assigned a shortage | Be informed and have acceptance/objection recorded; objection does not stop handover |
| Branch manager | Manage the branch and its custody; directly correct cashier report figures with preserved audit evidence and cashier notification (cashier approval is not required); submit expenses; approve final shortage liability before daily submission including their own share; and respect recipient rejection |
| Brand manager | Manage the brand and branches under owner delegation; approve expenses within granted permissions |
| Owner | Delegate brand-manager authority and approve expenses within permissions |
| Accountant | Review/final-close and review branch surplus; may correct report data and shortage allocation except gross/total sales under §16.5; correction invalidates prior manager approval and requires new approval before sending the day; not an expense approver |

## 10. Audit Preservation and Financial Integrity

### BR-24 — Preserve Evidence and Previous Versions

The system MUST preserve all amounts, attachments, rejection reasons, and corrections with the actor's identity/name and action timestamp.

Previous data and evidence MUST NOT be deleted. Preserve report revisions, old and new corrected values, correction reason, actor, timestamp, notification evidence where available, expense review cycles, and confirmed handovers. A Branch Manager's direct report correction does not require cashier approval, but it MUST remain fully auditable.

### BR-25 — Atomicity and Duplicate-Effect Prevention

Financial movements and their required audit records MUST succeed together or roll back together on failure.

Retries and concurrent requests MUST NOT duplicate financial effects. A report correction MUST trigger recalculation and any resulting shortage exactly once; the correction event itself MUST NOT create a duplicate shortage. This is an implementation acceptance condition, not authorization to change business policy.

## 11. Acceptance Cases

These are normative acceptance requirements. Their presence does not establish a test pass or completion of all workflows; the recorded S1-10 evidence and deferred scope are in §17. The identifiers are retained for traceability to the existing project reference.

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
| AC-12 | New cashier starts before predecessor report/receipt; later confirm 6,100 after request 6,150 | Work start allowed independently; no opening/receipt/liability at start; confirmed opening later 6,100; prior 50/report remain on previous shift | BR-05–06,12,14; §16.2 |
| AC-13 | Configured opening without confirmed receipt | Configuration alone is not actual received opening cash | BR-12 |
| AC-14 | Expense 200 from custody 1,000 | Submit→800; approval does not debit again; alternative rejection restores 1,000 | BR-18–19 |
| AC-15 | Rejected cash recovered outside the app | No additional credit | BR-20 |
| AC-16 | Paid expense 500 against custody 300 | Block until actual replenishment is recorded | BR-21 |
| AC-17 | Reject 200, correct same request to 150, resubmit | Preserve old cycle; deduct 150 once; balance 850 for the example | BR-22 |
| AC-18 | Concurrent decisions or a decision for an old revision | One valid decision for the current revision; no duplicate/conflicting effect | BR-23,25 |
| AC-19 | Accountant attempts expense approval | Deny approval; review permission does not grant approval authority | BR-23 and role matrix |
| AC-20 | Branch expense-custody expense occurs during a shift | Expected shift cash remains unchanged by the expense | BR-04 |
| AC-21 | Retry or failure during financial movement/audit persistence | No second effect and no partially persisted essential financial state | BR-25 |

## 12. Instructions for the Implementation Agent

1. Read this reference and the assigned sprint plan. Implement only the assigned scope while preserving every rule applicable to the paths being changed.
2. Trace actual routes, validation, services, observers/events, ledger writers, migrations, and dashboard consumers before editing. Use the code to establish current behavior, not to redefine required behavior.
3. Separate gross sales, counted cash, requested handover, confirmed receipt, and expense custody. Document DB/API/UI units before introducing conversions.
4. Derive financial results consistently on the server. Keep dashboard values and labels consistent with the required meanings; submitting a report must not appear as confirmed cash receipt.
5. Preserve revisions and evidence, protect confirmed handovers, and verify failure, retry, concurrency, and permissions for affected financial paths.
6. Link implementation and test evidence to BR and AC identifiers. Distinguish required outcomes from executed test results.
7. If an old test contradicts an approved rule, update its expectation with a documented rule reference; do not retain the wrong behavior merely to keep the test green.
8. Follow the sprint's Git, commit/push, review, and delivery instructions. This business reference does not authorize deployment, production-data mutation, or additional scope.

### Conflict and Clarification Protocol

If a new ambiguity matters to a concrete path, record:

```text
Issue ID:
Affected business rule and task:
Conflicting source wording:
Current code path and observed behavior:
Numerical example:
Decision required and options:
Decision owner: Mohamed for business meaning / Mahmoud for technical design:
Affected acceptance cases:
Resolution reference and date:
```

Block only the affected work and continue independent resolved tasks. Route business clarification through Mahmoud to Mohamed; do not send messages on Mahmoud's behalf. Do not repeat questions already answered in the latest clarification.

Conditional topics requiring clarification only when encountered:

- Other actual cash movements besides branch expense-custody expenses.
- A new computational exception or permitted tolerance outside the approved D5 policy; do not invent a tolerance.

Timeout-only financial closure, work-before-receipt, physical-return authority and accountant approval invalidation are resolved by §16; do not reopen them as unanswered questions.

## 13. Changes From Version 1.1

1. Submit closes the report while cash responsibility remains until recipient confirmation.
2. Default cashier name is provisional; complete allocation within the branch, manager approval before daily submission, and employee acceptance/objection are explicitly settled.
3. A mismatched handover requires rejection and correction before confirmation; no recipient override or fixed rejection limit.
4. All report data can be corrected after rejection while retaining previous versions and confirmed handovers.
5. Opening cash comes from actual confirmed receipt; receiving differences stay on the preceding shift.
6. Expenses use branch expense custody only; insufficient balances are blocked; brand manager, branch manager, and accountant responsibilities are distinct.
7. Corrected expenses retain the same request with a new review cycle; current-revision decisions cannot be duplicated, contradicted, or applied from old cycles.

### 2026-10-07 — Newly approved business clarification (version 2.0 retained)

This dated change-history entry records approved clarifications without changing the document version: Branch Managers may directly correct cashier report figures with preserved before/after values, reason, actor/time, cashier notification, immutable confirmed receipts, and one recalculation/shortage effect; pending incoming transfers do not block shift report submission but required transfers must complete before daily submission to the accountant; and manager-funded cashier transfers are limited to available recorded sales-cash after reservations/commitments, rejecting excess without movement or automatic shortage and never using expense custody. See the current normative wording in BR-05–06, BR-09, BR-11–12, BR-15–16, BR-24–25 and §15.

### 2026-10-07 — F1–F3 semantic clarification (version 2.0 retained)

Clarify source-backed physical pending cash through pendingIncomingCounted and reconciled counted cash (BR-03); distinguish original cashier allocation confirmation from manager-correction liability (BR-07 and §15); scope requiredHandovers to the server-derived daily close (§15). No new transfer states, schema, or rounding approval are introduced.

### 2026-10-09 — Approved D15/D16/D19 clarification (version 2.0 retained)

The user supplied the approved rules recorded in §16: elapsed shift time is not financial closure; starting work is independent of predecessor reporting and cash receipt; rejection with actual cash return leaves custody with the sender; rejected requests are corrected in place, while a recipient change requires cancellation and a new linked request; accountants may correct report data and shortage allocation except total sales; confirmed amounts are corrected through linked settlements without changing the original movement. Prior history and examples are retained. This entry records business requirements, not implementation approval or test completion.

### 2026-10-09 — Final S1-10 acceptance and F01/F02 decisions (version 2.0 retained)

Mahmoud accepted/closed S1-10 at `29b5c208055c7db34d8cb9826778dbe256cca204`: Phase 1, Phase 2 and S1-10R F01/F02 are ACCEPTED; F01/F02 KEEP, no ADAPT/DROP. Actual-start operational transfer, independent report ownership, explicit attempt-specific sender-confirmed return, redelivery identity and post-count fresh-count preservation are detailed in §16. Accountant corrections invalidate previous manager approval and require new approval; the full workflow remains S1-11. §17 records the accepted scope, evidence and OPEN / NOT_RUN release gates without asserting that deferred requirements are implemented.

## 14. Earlier 17-Point Clarification — English Reference Summary

This is an English summary of Mohamed's latest answers, not an original English quotation. The normative rules above remain the implementation reference.

| Original point | Approved clarification | Rule mapping |
|---|---|---|
| 1 | Submit closes the report; unreceived cash and partial-handover remainder stay the sender's responsibility | BR-05–06 |
| 2 | Reject 1,000; recount, correct, and resubmit 950; confirm 950; assign any established 50 shortage | BR-13 |
| 3 | Default cashier name can be changed/split; cashier confirms before closure; manager approves before daily submission even for their own share | BR-07–09 |
| 4 | Surplus is branch cash, reviewed by accountant, without employee/additional approval or extra sales | BR-11 |
| 5 | Full shortage can be split across one or more employees within the branch | BR-08 |
| 6 | Notify employee and record acceptance/objection; manager decides; objection does not stop handover | BR-10 |
| 7 | After rejection, correct all report fields and recalculate; preserve previous data and confirmed handovers | BR-15–16,24 |
| 8 | No final rejection count or automatic owner escalation; manager cannot bypass recipient rejection | BR-17 |
| 9 | Preserve numbers, attachments, rejection reasons, corrections, actor, and time | BR-24 |
| 10 | Sender records actual opening transfer; recipient confirms; confirmed amount becomes opening cash | BR-12 |
| 11 | Correct 6,150 to 6,100; confirmation establishes opening 6,100, while work may start beforehand; prior 50 stays on previous shift | BR-14; §16.2 |
| 12 | Expense debit on Submit; approval retains it; rejection reverses; external recovery adds nothing again | BR-18–20 |
| 13 | Custody 300 cannot fund paid expense 500 until replenishment is recorded | BR-21 |
| 14 | Owner or authorized brand manager; first decision on current revision wins; duplicates/conflicts prevented | BR-23 |
| 15 | Brand manager manages delegated brand scope; branch manager manages branch/custody/submission; accountant reviews/final-closes rather than approving expenses | Role matrix, BR-23 |
| 16 | Corrected expense starts a new cycle for the same request; previous cycle retained; corrected debit occurs once | BR-22 |
| 17 | Cash expense source is branch expense custody only, never cashier drawer or sales cash; expected shift cash is unchanged | BR-04 |

**Edition note:** This English Markdown retains the source rules and identifiers and records subsequent explicit approvals in §16–17. Those records do not imply completion of deferred workflows or production release approval.

## 15. Approved Clarification — 2026-10-07

This section records the newly approved decisions in the current normative reference. It supersedes any earlier pending interpretation while retaining prior history above.

### Branch Manager correction of cashier report

A Branch Manager may directly correct cashier report figures, including sales and payment-channel figures. Preserve each old value and new value, the correction reason, actor, and timestamp, and notify the cashier. Cashier approval is not required before daily submission. Confirmed handovers/receipts remain immutable. Recalculate expected cash and variance automatically once; create any resulting shortage once only and never duplicate it because a correction record exists. The resulting shortage follows full in-branch allocation and final manager liability approval under BR-09; neither the correction nor its resulting allocation requires cashier re-approval.

**Example:** network/card sales were recorded as SAR 500 and are corrected to SAR 400. If the report was balanced before correction and counted cash does not change, expected cash increases by SAR 100, variance becomes −SAR 100, and shortage is SAR 100. Apply the normal shortage-liability workflow; the Branch Manager may approve its assignment under BR-09.

### Pending incoming transfer when submitting the shift report

The cashier may submit the shift report while a manager or previous-cashier transfer remains pending confirmation/rejection. Until confirmation, sender responsibility remains and receipt status stays explicitly unconfirmed. If physical cash is already in the recipient's drawer, include it in counted cash; do not classify it as branch surplus merely because confirmation is pending. Shift report submission, receipt confirmation, and daily submission to the accountant are distinct. Shift report submission is allowed; daily submission/sending the day to the accountant waits for completion of the required handovers/transfers under the daily-close rules.

For daily submission, `requiredHandovers` is derived by the server from the shifts/reports included in that specific branch/workday close and their associated transfers. Each required valid request must complete the existing confirmed-receipt condition. Rejection/supersession preserves history but does not complete the required transfer; the corrected replacement must be confirmed. A required incomplete transfer blocks daily submission even after shortage liability approval. An unrelated pending transfer outside this scope does not automatically block the day. Shift report submission itself remains allowed with pending incoming transfers.

### Manager sales-cash transfer limit

A Branch Manager must not create/register a cashier transfer above available recorded sales-cash after amounts reserved or committed by pending transfers. Reject an excessive request without financial effect and without automatically creating a shortage. Physical cash not represented in the system must first be recorded through its legitimate source. Expense custody must never fund the transfer.

### D5 computational policy — approved by Mahmoud

Mahmoud approved the monetary computational policy: accept SAR inputs with at most two decimal places; reject excess precision with HTTP 422 before calculation; validate values against actual participating database column limits; use integer halalas internally; calculate VAT-inclusive net using half-up rounding; derive VAT as gross minus rounded net. The current Shift schema ceilings are DECIMAL(12,2) = SAR 9,999,999,999.99 and `branch_manager_shifts.handover_amount` DECIMAL(10,2) = SAR 99,999,999.99. This approval settles the computational decision without asserting that all later adapters or integrations are complete.

### 2026-10-07 — D5 computational policy approved

Mahmoud approved two-decimal SAR precision with HTTP 422 rejection before calculation, validation against actual DB column limits, integer-halalas arithmetic, half-up VAT-inclusive net, and VAT as gross less rounded net. The detailed approved policy is recorded above. Version 2.0 is retained.

## 16. Current Approved Business Rules — 2026-10-09 (D15/D16/D19)

**Authority and precedence:** This section records the latest approved rules and Mahmoud's accepted F01/F02 decisions supplied by the user in the project conversation. It supersedes conflicting earlier wording or interpretations, including receipt-before-work in BR-14/AC-12, the D16 start-blocking fallback, and the old D15 blanket accountant prohibition. Earlier decision history remains preserved. Normative requirements do not independently authorize product changes, publication or deployment; the separate final S1-10 acceptance is recorded in §17.

### 16.1 Shift timing is not financial closure (BR-03, BR-05–06, BR-25)

A scheduled shift boundary or elapsed time MUST NOT, by itself, financially close the report or create an automatic shortage. Operational time/status, report submission, cash receipt, and liability approval are distinct facts. A missing predecessor report or cash count is not evidence of zero cash or a shortage.

Overdue indication/notification MAY remain. Timeout alone MUST NOT create a count, receipt or shortage, mark the shift financially COMPLETED, or trigger financial close/bridge effects. This does not prohibit an explicitly authorized financial report submission or close.

### 16.2 Work starts independently of reporting and cash receipt (BR-05–06, BR-12, BR-14; AC-07, AC-12)

The incoming cashier MAY start work before the predecessor submits a report and before receiving or confirming the predecessor's cash. Starting work MUST NOT imply cash acceptance, confirmed opening, final liability approval, or ownership of the predecessor's report/shortage. Work acceptance and actual recipient cash confirmation must remain distinguishable.

Preserve the predecessor's report and responsibility. An incoming cashier's own report must not be mistaken for the predecessor's report merely because a shift retains reassignment history. Existing confirmed-opening rules remain effective: only actual confirmed receipts contribute to opening cash. The SAR 6,150/6,100 example establishes a confirmed opening of 6,100 and leaves the prior difference on the preceding shift; it does not impose a receipt-before-work prerequisite.

Reassignment, assignment or work acceptance alone MUST NOT end the predecessor's operational responsibility. At the replacement's actual start, atomically end the predecessor's operational interval and begin the replacement's at the same server timestamp, with one current operational owner of the reassignment chain. A chain represents a responsibility stream, not a permanent physical till/register entity. Preserve financial report ownership, history, count, allocation, approval and later financial-close rights. The replacement may end, report and hand over independently.

The start MUST NOT transfer cash, opening or liability; create a receipt/count/financial close; or close the predecessor's financial report. Locking/ownership protection must protect the report without blocking work solely because the predecessor's report or transfer is open. Admin live/late projections must exclude an operationally ended predecessor while preserving its financial state/close eligibility. Projection failure must not undo a committed actual start; recovery remains possible.

### 16.3 Cash acceptance versus rejection with actual return (BR-03, BR-05, BR-12–13; AC-07–09)

Actual recipient confirmation transfers custody for the exact confirmed amount. A request, manager approval, work start, or rejection alone is not a receipt. If cash is rejected and physically returned, it remains in the sender's custody; the rejection/return must not create recipient opening cash or charge the recipient.

Distinguish cash still physically held in the recipient's drawer pending confirmation from cash actually returned to the sender. Only the former may contribute to `pendingIncomingCounted` under BR-03. Rejection evidence is historical evidence, not permanent proof of current physical possession. Do not infer a physical return merely from a rejected status, or infer continued recipient possession after an evidenced return. Historical source-branch attribution must not follow the recipient's later employee-branch assignment.

**Physical attempts and return authority:** Each actual presentation/redelivery MUST have an independent immutable attempt identity, distinct from the request and report revision. Preserve the request/revision, original sender/recipient, historical branch/company provenance where available, amount, sequence and timestamp. Correction/resubmission of the same request does not by itself prove a new physical presentation. Historical source identity MUST NOT be manufactured from a participant's later branch/company assignment.

The exact recipient initiates a return against that attempt; ONLY the original sender may confirm it. No recipient self-confirmation, manager/system override, elapsed-time inference or rejected-status inference is permitted. Initiation alone leaves retained cash unchanged. Confirmed returns reduce only that attempt's retained physical cash; no new receipt/opening or duplicate movement is created. Exact attempt/state/actor checks, serialization and idempotency must prevent stale receipt/return commands and over-reserved returns.

**Example, SAR:** measured rejected cash 480 remains pending; recipient initiation of a 480 return still leaves 480 pending; original-sender confirmation makes that attempt's pending cash zero, with no recipient opening. Physical redelivery uses a NEW attempt; its later confirmed receipt of 480 becomes opening once. The old attempt's return cannot suppress or reduce the new attempt.

**Return after a report count:** Preserve the old count, revision, rejection, allocation and approval as historical evidence. Where correction remains editable, create a new uncounted revision and require a fresh physical count; do not rewrite the previous count or silently repost/reverse shortage liability. `PHYSICAL_RECOUNT_REQUIRED` blocks financial handover/close/finalization and daily submission until the fresh count, including affected approved carry-over reports. If an immutable/submitted daily or financial boundary prevents correction, return `REPORT_REOPEN_REQUIRED` without silently reopening history; full reopen orchestration belongs to S1-11.

The accepted attempt/return implementation is prospective. Legacy records with no safely mapped attempt retain nullable identity and existing behavior; do not invent return facts or backfill historical physical attempts. A measured rejected amount is not necessarily the requested amount.

### 16.4 Correct the same rejected request; cancel before changing recipient (BR-13, BR-17, BR-24–25; AC-08, AC-11)

Correct and resubmit a rejected handover on the SAME request, preserving original values, rejection evidence and correction history. Sending that cash to another recipient requires cancellation of the old request and creation of a NEW request, with an auditable relationship and protection against duplicate confirmation or movement. Do not silently change the recipient on the old request or discard its history. Cancellation is not a cash receipt and must not itself invent a custody movement.

The old cancelled/superseded request MUST become impossible to confirm; reservation release/replacement must happen once. This general different-recipient workflow is approved business behavior but remains **R4b / S1-11**, distinct from accepted new-attempt redelivery to the same recipient.

### 16.5 Accountant correction and shortage redistribution (BR-08–09, BR-15, BR-24–25; AC-03, AC-10, AC-21; role matrix)

An authorized accountant MAY correct report data and redistribute shortage liability, EXCEPT total sales. Preserve a complete history of old/new values, reason, actor and time; issue the required notification; update resulting balances without repeated charging. Company/branch authorization and existing receipt immutability continue to apply.

This accountant exception does not revoke the earlier Branch Manager correction permission, including sales, and does not grant the accountant expense-approval authority. The old D15 blanket prohibition is business-superseded; a retained runtime guard is an implementation gap, not final accepted accountant behavior. Do not remove `BRANCH_ALLOCATION_AUTHORITATIVE` as a partial patch that exposes a writer without the versioned correction safeguards.

If an accountant changes report data or shortage distribution after branch-manager approval, the change MUST invalidate the previous approval for the current revision. Preserve BOTH versions, the previous approval and the complete audit trail. The current report must require a new branch-manager approval before the day can be sent, even if the correction removes the shortage. The accountant cannot approve on behalf of the manager; manager approval of an older revision cannot authorize the corrected one.

The workflow MUST enforce company/branch permissions and the gross-sales exception; apply balance deltas atomically with the new revision/audit state; distinguish already-posted liability from unposted allocation; and prevent repeated loading of the shortage on retry or duplicate correction. Preserve old/new values, reason, actor and timestamp; notify the cashier and affected parties through the required notification workflow without duplicate logical notifications. Existing confirmed receipts and receipt-derived opening stay unchanged. The already-approved Branch Manager correction exception does not introduce cashier reapproval.

The complete **R5 versioned accountant correction workflow is the first S1-11 dependency**, including report state/current-revision approval, balance correction, audit, idempotency and notifications. Approval invalidation is an approved business rule, not an unresolved decision; its full product implementation is not part of accepted S1-10.

### 16.6 Correct confirmed money through a linked settlement (BR-16, BR-24–25; AC-10, AC-21)

A confirmed handover amount MUST NOT be edited inside the previous receipt or financial movement. Correct it through a linked settlement that preserves the original receipt, movement, amount and evidence. The settlement must remain attributable and auditable, and must not duplicate the original transfer or charge. This requirement is distinct from correcting an unconfirmed rejected request under §16.4.

Preserve `CONFIRMED_RECEIPT_IMMUTABLE`. The linked adjustment writer remains **R6 / S1-11**; no accepted S1-10 change opens the original receipt for editing.

### Verification and implementation boundary

The rules above are approved requirements; distinguish them from the scoped implementation acceptance in §17. Reevaluate prior D15/D16/D19 findings against the current rules and actual code rather than an older interpretation. Work start before predecessor reporting or receipt is permitted and is not, by itself, a finding. Preserve report ownership and historical evidence. Do not reopen accepted F01/F02 without a demonstrated new regression; any future product work requires its assigned scope and verification. That 2026-10-09 document update started no S1-11 work. Subsequent S1-11 authorization and evidence are recorded separately in §19.

## 17. Current Implementation Acceptance and Remaining Scope — 2026-10-09

### 17.1 Mahmoud's final S1-10 decision

**S1-10: ACCEPTED BY MAHMOUD / CLOSED.**

| Item | Final status |
|---|---|
| Accepted CODE BASELINE | `29b5c208055c7db34d8cb9826778dbe256cca204` |
| Final reconciliation review base | `e5907558148f2cd3cdf5db90d5a6ab8d622a4854` |
| Phase 1 / Phase 2 | ACCEPTED / ACCEPTED |
| S1-10R F01 / F02 | KEEP / ACCEPTED; no ADAPT or DROP |
| Accepted F01 commit | `b71f6ac7` — operational responsibility transfer on actual start |
| Accepted F02 commit | `29b5c208` — physical attempts and sender-confirmed returns |

Accepted behavior includes independent operational/report ownership and actual-start transfer; reminder-only overdue handling; confirmed receipt as the sole opening source; attempt-specific explicit physical return and redelivery; immutable historical rejection/count evidence with fresh count after return; historical branch/company attribution; failed-attempt upload cleanup without deleting previous evidence; locking/re-read and idempotency protection; and unchanged FIN-01/accepted financial calculations. Acceptance does not close the deferred requirements below. The accepted code SHA remains fixed regardless of any documentation closure commit.

### 17.2 Recorded supporting evidence

Evidence classification remains **DEVELOPER_EXECUTED**. These are the accepted prior implementation results, not tests rerun while updating this reference.

| Evidence | Recorded result |
|---|---|
| Focused regression | 166 tests / 1119 assertions — PASS |
| Full Unit/Feature/NFR | 1522 tests / 8997 assertions — 0 failures, 0 errors, 1 SQLite-specific skip |
| PHP syntax / Pint / diff check | PASS / PASS / PASS |
| Independent source review | PASS for the bounded F01/F02 scope; not independent runtime/MySQL evidence |

The full-suite skip concerns a column-length driver-error check that SQLite does not enforce. Commands, scope and historical evidence: [verification](./verification.md), [task register](./task-register.md), [final acceptance handoff](./s1-10-final-acceptance-handoff.md), and [accepted F01/F02 implementation contract](./s1-10r-operational-and-physical-return.md).

### 17.3 Release/deployment gates — OPEN

These are **not S1-10 acceptance blockers** and MUST NOT be represented as PASS:

- **MYSQL CONCURRENCY = NOT_RUN**.
- **Production MySQL migration/rollout = NOT_RUN**.
- **AssabAPP integration/release verification = NOT_RUN**.
- **Dashboard integration/release verification = NOT_RUN**.

SQLite does not close the MySQL concurrency gate. S1-10 acceptance is not production deployment approval.

### 17.4 Carry forward to S1-11

1. **R5 first dependency:** versioned accountant report/allocation correction with the gross-sales exception, invalidation of prior approval, approval of the current revision, atomic balance correction, audit, idempotency and notifications (§16.5).
2. **R4b:** cancel/supersede the old request and create a linked replacement for a different recipient; prevent confirmation of the old request (§16.4).
3. **R6:** linked adjustment after a confirmed receipt, keeping the original immutable (§16.6).
4. Removal/replacement of the legacy fixed rejection limit; BR-17 remains normative, but this remaining implementation gap is not declared resolved by S1-10 acceptance.
5. Remaining report correction/reopen/history orchestration beyond the accepted post-return fresh-count boundary.
6. Daily-submit/current-revision approval rules, including accountant correction invalidation.
7. Required liability repost/delta handling without duplicate financial effects.

S1-10 product work is stopped. S1-11 MAY start from the accepted S1-10 code baseline, subject to normal branch synchronization and its normal authorization/verification workflow. It was **NOT STARTED** by the 2026-10-09 document update. See §19 for the subsequent implemented scope and latest authorization. Reverify the target branch's exact SHA and ancestry before synchronization; do not blindly merge/cherry-pick, overwrite, reset, rebase or force-push existing sprint history. Permission to start S1-11 closes none of the release gates.

## 18. Implementation and Results by Stage — S1-01 Through Current State

### 18.0 Evidence scope and status interpretation

This dated history is compiled from the available [task register](./task-register.md), [verification record](./verification.md), Git history and stage handoffs. It records what each stage delivered at the time and how later stages extended or superseded it. No application tests, migrations or client changes were executed for this documentation update.

- **SOURCE_INSPECTION_ONLY / documentation verification:** source paths, contracts and design consistency were checked; this does not prove runtime behavior.
- **DEVELOPER_EXECUTED:** implementing-session test evidence. Historical source documents sometimes use another execution label; this summary does not promote implementer runs to independent review.
- **Historical reviewer-reported evidence:** explicitly identified below when the retained acceptance record describes a reviewer run; not freshly reproduced here.
- **NOT_RUN / BLOCKED:** distinct from PASS. An earlier MySQL baseline test does not close later financial concurrency or production-rollout gates.
- **Acceptance:** only the explicit decision recorded for that stage. S1-10 acceptance does not manufacture independent acceptance decisions for S1-01–S1-07.

| Stage | Latest status found in the available record | Delivery type |
|---|---|---|
| S1-01 | Ready for review; no independent formal acceptance recorded | Baseline/environment/source map and one fixture correction |
| S1-02 | Ready for review; no independent formal acceptance recorded | Monetary contract/source trace |
| S1-03 | Ready for review; no independent formal acceptance recorded | Schema and compatibility ADR |
| S1-04 | Ready for review; no independent formal acceptance recorded | State/permission/revision design |
| S1-05 | Ready for Mahmoud design review; no independent formal acceptance recorded | API/financial/session blueprint |
| S1-06 | Ready for review; D5 approved, stage acceptance not separately recorded | Calculation and monetary validation implementation |
| S1-07 | Internal layer closed for sequencing; not accepted end-to-end in the register | Scoped liability model/services and guards |
| S1-08 | ACCEPTED BY MAHMOUD at `8ddb90a2` | Atomic financial writers and confirmed receipts |
| S1-09 | Development scope closed; proceed to S1-10; production release gated | Idempotency/replay and commit recovery |
| S1-10 | ACCEPTED BY MAHMOUD / CLOSED at `29b5c208` | Count/adapters/reconciliation and accepted F01/F02 corrections |

### 18.1 S1-01 — Working Baseline and Route Map

**Delivered:** portable PHP/Composer/pnpm setup using locked dependencies; isolated MySQL migration baseline and safe disposable test-schema separation; current route registration; source-backed report/handover/variance/custody/Admin-close writer and transaction map; environment/recovery/preflight/test-runner evidence. The only recorded product-support correction removed the obsolete `map_coordinates` field from `Modules/Branch/database/factories/BranchFactory.php`, matching the fully migrated schema. No migration or business logic was changed for that correction.

**Recorded results, historical developer evidence:**

| Check | Outcome and limit |
|---|---|
| Six focused MySQL files | 41 tests / 144 assertions / 0 failures / 0 errors; separate processes on disposable `assab_s1_test`, MySQL 8.4.11 at local port 3310 |
| Financial/security files | `ShiftCycleFixesTest`, `ShiftCloseChainTest`, `ShiftHandoverVarianceCustodyTest`, `SalesVarianceAllocationTest`, `HandoverLedgerDateTest`, NFR `AuthenticationTest` |
| Migration inventory | 283/283 migrations; recorded 212 base tables, 2 views, 2 procedures, 179 foreign keys; no broad seed |
| Bounded first-party PHP syntax | 1,173 files, zero syntax errors |
| Route registration | 1,734 API routes registered; registration does not establish handler acceptance |
| BranchFactory syntax/Pint | PASS |
| Dashboard typecheck | Earlier PASS recorded without retained exact command/test SHA; later attempt BLOCKED by pnpm/Node EPERM before TypeScript |

**Failure history:** the initial six-file attempt had 39 fixture-setup errors, not demonstrated financial assertion failures. The passing 41/144 rerun supersedes that fixture blocker. The exact historical test-run HEAD was not captured; the factory correction was later committed as `21d06ffe`. Do not present that commit as a proven exact tested SHA.

**Remaining boundary:** no full application suite or comprehensive API-to-UI acceptance was run in S1-01. Migration of an empty isolated schema is not populated-database upgrade safety or two-connection concurrency evidence. The separate Purchase test-data seeder still referenced the removed field in the stage record; it was neither run nor corrected in that scope.

**Sources:** [baseline](./baseline.md), [route map](./route-map.md), [migration preflight](./migration-preflight.md), [test workflow](./test-runner-workflow.md), verification sections “Focused MySQL rerun after BranchFactory correction” and “Command and repository-state evidence”.

### 18.2 S1-02 — Monetary Units and Field Meanings

**Delivered:** `money-contract.md` traced gross/net/VAT/cards/apps, `cash_collected`, independent count, opening/closing, expected/variance, handover and ledgers through migrations, casts, services, resources, bridges, Dashboard and read-only AssabAPP models. It identifies legacy SAR versus Admin/Dashboard integer halalas, conversion boundaries, sign meanings and null/zero differences. The R04 correction separates custody, sender responsibility, provisional allocation, cashier confirmation, employee response, manager final approval and accountant review as AS-IS / target / gap.

**Recorded checks:** 39 cited source paths and 13 required monetary concepts were checked; source/reference integrity, documentation arithmetic and diff/scope checks passed. A separate PowerShell arithmetic example converted `115.00`, `0.00`, `0.01`, `115.37`, `-20.00` to halalas and back and checked FIN-01. It did not invoke application adapters, persisted endpoints or UI code.

**Implementation result:** documentation only; no new runtime financial enforcement, migration or client implementation. End-to-end 115/zero/null/fractional/confirmed-opening round trips were NOT_RUN at this stage. Later S1-06 and S1-10 provide calculation/adapter/count evidence within their scopes; they do not turn the S1-02 arithmetic script into an application test.

**Trace:** monetary-contract and authority corrections include `788e5065`, `9c39fafc`, `3f12f5e8`; source [money contract](./money-contract.md) and verification “S1-02 contract documentation verification” / “R04 liability-authority documentation correction”. Formal stage acceptance is not separately recorded.

### 18.3 S1-03 — Minimal Schema and Compatibility ADR

**Delivered:** `schema-adr.md` corrected R01/R05/R07/C03 and documented a stable report aggregate with revisions independent of the existence of a handover. End-only and native Admin-close reports can exist without a request; requests and immutable receipts have separate identities. Admin values remain integer halalas; the legacy SAR-to-halalas bridge conversion occurs once. Additive structures and nullable historical evidence avoid invented receipt/count backfill.

**Recorded result:** source/document verification only. No database preflight, migration, backfill, seed or runtime acceptance test was executed in the S1-03 task. Duplicate/cardinality checks, exact effect identities and compatibility details were deferred to later design/implementation owners. The proposal is not evidence that a schema existed then.

**Later delivery:** S1-08 implemented report/revision, transfer and receipt structures; S1-10 added immutable counts/rejection evidence and later operational-chain/attempt/return structures. Those migrations must be attributed to their implementing stages, not S1-03.

**Trace:** `65a21656`, `279b5617`, `a2876393`; source [schema ADR](./schema-adr.md), verification “S1-03 schema and compatibility ADR correction”. Status remains ready for review in the available register.

### 18.4 S1-04 — State, Permission and Revision Invariants

**Delivered:** a source-backed AS-IS/target matrix for report submission, work, handover/receipt, shortage allocation, employee response, manager approval, daily submission and preserved revisions. It maps actor/company/branch scope and BR-05–10/11–17/24–25, identifies the missing current-revision daily liability guard, and records the legacy fixed rejection cap/destructive reset as conflicts rather than approved behavior.

**Recorded result:** static source references, route/actor scope, transition consistency, rule mapping and revision checks passed. Runtime permissions, new states, migrations, DB cardinality preflight, replay/concurrency and client acceptance were NOT_RUN. R02/R05 remained partially resolved because actual trusted count/receipt evidence and endpoint-specific replay were not yet delivered.

**Later delivery:** internal liability guards in S1-07, atomic receipt/revision writers in S1-08, replay in S1-09 and trusted count/operational/return evidence in S1-10 address assigned portions. Full current-revision correction/daily-submit/reopen orchestration remains S1-11.

**Trace:** `52ce4b0b`, `aaa33352`; source [state/permission/revision document](./state-permission-revision.md), verification “S1-04 state, permission, and revision invariant design”. No independent formal stage acceptance is recorded.

### 18.5 S1-05 — API Blueprint and Design Review

**Delivered:** one financial/report identity blueprint with explicit existing versus proposed routes, monetary wire units, response/error envelopes, liability actors, revision/stale-decision behavior, receipt versus request/effect/replay identities, lock/write ownership, and post-commit projections. The corrected package includes 15 paired request/success/error examples and an actual Dashboard auth/session trace assigning backend rotation/revocation and frontend refresh/retry to S1-12/S1-13. It contains no new employee system, manager-workday system or broad approval layer.

**Recorded result:** source-reference, route/request/response, unit-vector, revision/actor, idempotency/transaction design, compatibility-gap, diff, conflict-marker and secret-pattern checks passed as documentation/source checks. Proposed APIs, runtime authorization/replay, migrations, concurrency and client behavior were NOT_RUN. The RX-01/RX-02/RX-03 mappings require actual status/amount/row assertions in later acceptance, not placeholder assertions.

**Decisions and precedence:** D5 was approved later and is now the policy in §15. Later approved same-request correction, accountant correction and actual-start/physical-return rules in §16 override conflicting historical proposal wording. In particular, old “new exact request on every amount correction” or accountant-review-only interpretations are not the current rule; a different recipient requires the R4b replacement workflow.

**Trace:** `af9c9781`, `9a8e0ff7`, `02eaad50`, `31af7c8f`; source [API contract](./api-contract.md), verification “Consolidated S1-01–S1-05 correction pass” and “Final documentation correction verification”. Ready for Mahmoud design review is not recorded formal acceptance.

### 18.6 S1-06 — Calculation and Monetary Validation

**Implemented:** `app/Support/ShiftFinancialCalculator.php` is the shared integer-halalas calculator for VAT-inclusive net, residual VAT, expected cash, signed variance, shortage and surplus. Shift save/preview and manager update/summary/resource paths use it instead of `gross × 0.15`. F1 added reconciled counted cash by excluding evidenced pending incoming; F2/F3 contract corrections distinguish manager correction and daily-required transfers. Shared validation rejects excess precision/invalid shape/per-column overflow with 422 before calculation, while handling approved-path representation noise without accepting real third-decimal amounts.

**Later audit corrections:** N-01 normalizes mobile floating representation noise at legacy inputs; N-02 reads preserved historical net/VAT and sums stored per-shift splits, including manager end/daily close; N-03 permits signed stored amounts without read exceptions; reassign-with-handover stores inclusive VAT correctly. `roundingPendingD5` was replaced with `netRounded` after D5 approval. The stage audit retained a separate D10 confirmation item and a NOT_RUN negative-value replica inventory; this history does not silently close those items.

| Historical developer evidence | Result / boundary |
|---|---|
| Initial calculator | 9 tests / 37 assertions PASS |
| F1–F3 calculator correction | 15 tests / 54 assertions PASS |
| Validation final evidence | Calculator 17/58; route/request tests 26/234; 43 tests / 292 assertions across the recorded runs |
| October 8 compatibility/stored-split audit | 62 tests / 389 assertions PASS, real authenticated SQLite legacy paths included |
| Audit full suite | 1,292 tests; 71 errors + 2 failures, same 73 bad identities as comparison baselines; 0 new identities. Historical, later superseded by S1-08 clean suite |
| Changed PHP syntax/Pint and diff checks | PASS within the respective changed-file scopes |

**Calculation outcome:** FIN-01 remains gross115/cards50/apps25/confirmed opening10/count30 → net100/VAT15/expected50/variance−20/shortage20. D5 approves two-decimal SAR, actual column limits, integer halalas, half-up net and VAT as the residual. An early whole-project Pint run had two unrelated style findings; it was not a failure of the changed S1-06 files or a current whole-project cleanliness claim.

**Boundary:** S1-06 did not manufacture independent count or confirmed-opening evidence from legacy sales fields; integration belonged to S1-10/S1-11. No client or production migration work. Stage status in the register remains ready for review, independently of D5 approval.

**Trace:** `6fcce32c`, `dc73ab29`, `c18c2f23`; source verification “S1-06 calculation implementation”, “Final targeted S1-06 validation correction”, “Mahmoud D5 approval” and “Audit corrections — 2026-10-08”.

### 18.7 S1-07 — Internal Shortage Liability and Branch Surplus Layer

**Implemented:** additive allocation/share snapshots and typed in-branch cashier/manager/employee identities; exact complete shortage shares; owner confirmation; separate employee accept/object with reason/time; manager final approval including explicit self-share; preserved prior versions; stale revision/amount/version rejection. No shortage allocation is created for balanced/surplus reports. A shortage corrected to zero/surplus no longer fails readiness because of old allocation; that allocation remains history, and a later shortage requires fresh allocation/approval.

`DailyLiabilityGuard` provides transaction-bound readiness/submit locks and reasoned release. `shift_liability_daily_locks` is per report/workday, so carry-over included in two days stays locked until both are released. Allocation/confirmation/approval are refused under active daily locks; employee response remains available. `salesChannelCheck` separates a reported channel-entry difference from counted-cash shortage/surplus.

| Historical developer evidence | Result / boundary |
|---|---|
| Initial new allocation/liability tests | 38 tests / 64 assertions PASS |
| Initial combined S1-06/S1-07 | 81 tests / 356 assertions PASS |
| Post-integration focused | 100 tests / 453 assertions PASS |
| Stale-readiness service / combined correction | 27/65 and 102/469 PASS |
| Internal completion focused | 113 tests / 535 assertions PASS; includes 7 real migrated SQLite-schema tests |
| Internal completion full suite | 1,343 tests; same 73 historical bad identities, 0 new; not a clean suite at that checkpoint |
| Changed-file syntax/Pint | PASS in the recorded scopes |

**Boundary at delivery:** the evidence adapter deliberately failed closed; synthetic test sources were not production truth. No public allocation/confirm/respond/approve or daily-submit/reopen route was wired, and no personal-ledger/payroll movement was created. Later S1-10 binds the real report-count evidence portion; public liability/current daily-scope integration remains S1-11. MySQL lock/gap-lock/deadlock/concurrency evidence remains NOT_RUN.

**Status and trace:** Mahmoud closed the internal layer for sequencing, with integration reassigned to S1-08/S1-10/S1-11; no end-to-end acceptance is inferred. Main integrated commits `7fd1d15c`, `a946b1f4`, `c018fa01`; original implementation `ea5e8d90` was integrated historically. Sources: [S1-07 handoff](./s1-07-implementation-handoff.md), verification “APPROVE A”, “corrected-report liability readiness” and “internal layer completion”.

### 18.8 S1-08 — Atomic Financial Writers and Confirmed Receipts

**Implemented:** stable `ShiftReportAggregate`/`ShiftReportRevision`; manager-to-cashier request separate from receipt; immutable typed `CashierShiftHandoverReceipt` with one receipt per source; `ShiftTransferReceiptService` as sole authoritative receipt/effect writer. Exact recipient confirmation writes required custody/personal-ledger rows, request state, confirmed-opening projection and audit together. Request creation/manager review alone creates no receipt; cashier-to-cashier review stays pending for the named cashier. Cashier-to-manager receipt belongs to the exact addressed manager/workday.

Financial report close and Admin final approval lock/re-read their sources and roll back required writes together. Bridge/statistics are after-commit projections; projection failure is logged. Two additive migrations (`2026_10_08_000003` report/transfer/receipt structures, `000004` manager recipient fields) preserve existing history; the latter rollback refuses when manager receipt evidence exists.

**Final corrections:** uniquely assigned active manager rules (D12); open addressed handovers block manager deactivation/transfer/delete (`MANAGER_HAS_OPEN_HANDOVERS`); discovery still returns valid cashier choices when manager assignment is missing/ambiguous; only the addressed recipient corrects/rejects (`ONLY_ADDRESSED_RECIPIENT`). S8-05 labels manager cash-out `Handover to Cashier`; funding is personal sales cash after pending reservations, never expense custody. Confirmed requests cannot be destructively reset. Essential failures are generic/logged, not leaked. S8-09 defers self-shortage posting to S1-11 final manager approval; S8-12 requires drain-before-deploy with zero pending handovers in maintenance mode.

| Recorded evidence | Result / timing |
|---|---|
| Phase 1 combined focused | 142 tests / 676 assertions PASS |
| Phase 2 affected / S1-06–07 | 45/203 and 113/535 PASS |
| Final F-01/F-02/F-03 regressions | 4 tests / 38 assertions PASS |
| Final affected files | 87 tests / 435 assertions PASS |
| Final accepted serial Unit | 62 tests / 1,764 assertions, 0 failures/errors/skips |
| Final accepted serial Feature | 1,181 tests / 5,702 assertions, 0 failures/errors, 1 skip |
| Final accepted serial NFR | 153 tests / 504 assertions, 0 failures/errors/skips |
| Final combined | 1,396 tests / 7,970 assertions, 0 failures/errors, 1 SQLite-specific skip |
| Final changed PHP syntax/Pint | PASS, 17 files; diff check PASS |

**Baseline distinction:** exact comparison found 96 Phase-2-only bad identities, zero shared/current-only bad identities; all 96 were green in the final run, including the original 73 baseline-hard-close identities. Earlier red suite/comparison records remain historical, not current failures. The acceptance record describes the final 1,396/7,970 suite as reviewer evidence; these results were not reproduced for this reference update.

**Acceptance and trace:** ACCEPTED BY MAHMOUD at `8ddb90a2`; phases `9fc8ebac`, `569d00e7`, corrections `18255d04`, `8ddb90a2`, verification-workflow stabilization `601504bf`. Current acceptance takes precedence over the earlier pending-review heading in the historical handoff.

**Open boundaries:** MySQL concurrency/DDL, production rollout and actual AssabAPP integration/release remain open. Removed handover-notification delivery is a separate deferred restoration; if restored, send after commit from the receipt writer without resurrecting the duplicate ledger listener. Trusted independent count came in S1-10; public liability/history/daily reopen remain S1-11. Sources: [S1-08 handoff](./s1-08-implementation-handoff.md), [deployment readiness](./s1-08-deployment-readiness.md), verification “Baseline hard-close and S1-08 correction pass”.

### 18.9 S1-09 — Idempotency, Replay and Commit Recovery

**Implemented:** durable command reservations, canonical payload hashes and stored original response replay; actor/action/resource identity shared across `/api` and `/api/v1` aliases; authorization-scope checks before disclosure/replay. Same key/same command/body returns the stored status/body; changed payload conflicts; cross-actor use cannot disclose the original result. Key snapshots retain the existing 90-day response policy while durable effect/command protection is distinct.

Three completion modes have different boundaries: authoritative `writer` completes inside its business transaction; `transaction` wraps required writes plus response snapshot together; other `after-commit` commands can fail closed with a held reservation after a commit/response interruption. Expired recovery is permitted only where mode/state prove no financial commit. Admin shift-close aliases were moved to same-commit `transaction` completion; this does not make every Admin command recoverable.

**Correction passes:** body-less JSON actions canonicalize to `{}` while JSON null remains distinct and malformed nonempty input fails; mutable company/branch/role scope cannot select another reservation, and changed scope returns `IDEMPOTENCY_SCOPE_CHANGED` without execution/disclosure. The commit counter survives a commit-then-throw or transaction reopening; processing reservations remain held when business commit cannot safely be replayed. Workday cache invalidation runs after commit; variance rollback handles any `Throwable`.

| Historical developer evidence | Result / timing |
|---|---|
| Early full serial Unit / Feature / NFR | 65/1,768; 1,190/5,780 (1 skip); 153/504 |
| Early full combined | 1,408 tests / 8,052 assertions, 0 failures/errors, 1 skip; before later correction passes |
| Comparison to accepted S1-08 | 0 shared/current-only/baseline-only bad identities; all 1,396 earlier identities retained, 12 new passing identities |
| Broader correction focused | 171 tests / 986 assertions PASS |
| Final bounded closure focused | 82 tests / 499 assertions PASS |
| Separate real commit→exception recovery probe | 1 test / 8 assertions PASS, no wrapping test transaction |
| Final bounded closure total | 83 tests / 507 assertions PASS; 0 failures/errors/risky/skips |
| Final changed-file checks | Pint PASS for 3 PHP files; middleware syntax and diff check PASS |

**Timing distinction:** the two-P1 checkpoint originally said tests NOT_RUN; later authorized 83/507 closure supplies executed coverage. The 1,408-test suite preceded final corrections and must not be advertised as a post-final-correction full run. No full suite was run in that final bounded closure; later S1-10's 1,522-test accepted run is separate evidence.

**Covered commands:** cashier receipt acceptance, manager handover approval, workday handoff approval, cashier/manager end and end-with-handover, start-handover/record-handover and covered manager reassignment, plus accountant/company/branch Admin shift-close aliases. Exact coverage/modes remain in verification/API contract; no universal route replay claim.

**Status and limits:** development scope closed by Mahmoud for progression to S1-10, not production readiness. Mobile keys remain optional for compatibility: no-key requests have business-state/effect guards but no original-response replay guarantee. Client stable-key behavior/enforcement, other Admin after-commit recovery and MySQL two-connection locking/deadlocks remain explicit boundaries. A changed-scope conflict requires reconciliation, not automatic new-key resubmission.

**Trace:** `4dbffd8b`, `a387872a`, `7954b4a7`, final `fbdbd6ce`; source verification “S1-09 idempotency acceptance evidence”, “correction pass”, “two-P1 correction” and “bounded closure”, plus [API contract](./api-contract.md).

### 18.10 Subsequent S1-10 Implementation — Adapters, Trusted Count and Accepted Corrections

The final acceptance is §17; this subsection adds delivery detail without changing that code baseline.

**Phase 1:** four bridge/mirror sites (`BridgeLegacyCashierShift`, `BridgeManagerDailyClose`, `LegacyManagerShiftMirror`, `LegacyShiftMirror`) use `storedSarToHalalas` once instead of binary-float multiplication. Legacy SAR keys/signs/types and Admin integer-halalas meanings remain intact; configured opening is not reclassified as a receipt. Recorded affected tests: **72 tests / 400 assertions PASS**, with 7 changed PHP files passing syntax/Pint/diff checks. Commits `e8661736`, audit follow-up `4f6cf5b9`; Phase 1 formally accepted.

**Phase 2:** additive immutable per-revision `shift_report_cash_counts` and `shift_transfer_rejection_evidence`; `ShiftCashCountService` reads actual confirmed receipts and evidenced pending physical cash. Required `counted_cash` distinguishes missing/null from explicit zero. Cashier end/end-with-handover and counted manager reassignment atomically commit report/revision/count/full in-branch allocation; balanced/surplus create no employee liability. A real count-backed report `LiabilityEvidenceSource` replaces unavailable report evidence; daily-scope evidence and full correction orchestration remain S1-11. Admin projections use the current trusted count; unknown historical counts remain explicitly unknown, never implied zero.

D6 exposes a nullable single-shift `cash_reconciliation` response and Admin `cashCountState` / `pendingIncomingCountedHalalas`, with read-only preview/detail support. Counted shortages do not become automatic personal-ledger charges in S1-10. The matching client release must send the new count/allocation fields and read nullable responses; no actual AssabAPP/Dashboard integration pass is claimed.

**Corrections and precedence:** D14/D17/D18 same-request correction/revision/evidence checks and C2 historical branch attribution/C3 locking/C4 failed-upload cleanup were preserved. The old D16 counted-start block was superseded by independent report ownership and actual-start operational transfer; the old D15 blanket accountant restriction is business-superseded but its full safe replacement remains R5. Historical tests for the old start-blocking interpretation do not define accepted behavior.

**F01/F02:** actual-start interval transfer preserves predecessor financial report rights; timeout only indicates overdue; independent physical attempts, recipient-initiation/original-sender-confirmation, new redelivery IDs and post-return fresh-count guards protect history and once-only effects (§16). No opening before actual confirmed receipt, no liability/receipt/close merely from work start, no physical-return inference from rejected status.

**Evidence chronology:** the prior approved reconciliation run had 130 tests / 888 assertions PASS; the final F01/F02 accepted focused run is **166/1,119 PASS**, and the final full suite is **1,522/8,997, 0 failures/errors, 1 SQLite-specific skip**. These are separate runs, not counts to add together. Evidence remains DEVELOPER_EXECUTED; independent source review PASS is limited to bounded F01/F02.

**Trace:** initial count/allocation/bridge/D6 commits `aa9a756d`, `181feb91`, `3df0b902`, `193ea3c7`; later D14–D19 `d131fd72`, `c189633f`, `dd2977eb`, `776df28d`; approved reconciliation `e5907558`; accepted final corrections `b71f6ac7`, `29b5c208`. Sources: verification “S1-10 Phase 1”, “Phase 2”, “Approved rule reconciliation” and “S1-10R F01/F02”, [final acceptance handoff](./s1-10-final-acceptance-handoff.md).

### 18.11 Subsequent Scope — S1-11 Through S1-15

| Stage | Current recorded scope / next dependency | Implementation/result status |
|---|---|---|
| S1-11 | R5 first; R4b/R6; legacy rejection-limit replacement; public liability/current-revision approval; report correction/reopen/history; daily-submit scope; safe liability deltas | Implemented corrective scope through Phase 10 on the preserved b854e370 baseline plus AUD-05. See §19 and the current verification report; independent final acceptance and unresolved financial activation gates remain open |
| S1-12 | Dashboard backend session lifecycle, rotation/revocation and auth blueprint integration | Planned; no session implementation/test result recorded here |
| S1-13 | Dashboard refresh and financial retry with stable user-action keys and compatible session handling | Planned; no client implementation/test result recorded here |
| S1-14 | Real Dashboard shift data/correct values; D7/cross-client scope requires Mahmoud's decision per register | Proposed / blocked pending decision in the available register; no delivery result claimed |
| S1-15 | Complete sprint verification/delivery after required implementations and explicit unresolved gates | Planned; no full sprint acceptance or release claim |

Full expense-week behavior, procurement conversions and payroll-import conversions are not delivered merely because shift calculations are accepted. Keep their assigned scope and BR-18–23 requirements separate from recorded Sprint 01 shift evidence.

### 18.12 Later Decisions and Update Discipline

Current dated sequence: October 7 baseline/source contracts and D5 approval; October 8 calculation audit, internal liability completion and S1-08 final acceptance; October 9 S1-09 progression closure, S1-10 adapters/count/reconciliation, approved rule reconciliation and final F01/F02 acceptance. The accepted S1-10 CODE BASELINE remains `29b5c208055c7db34d8cb9826778dbe256cca204` despite subsequent documentation edits.

At this update, upstream/local sprint was last verified at `47066902`, while personal-fork sprint was `b84d1952` with divergent history. Exact SHAs and left/right ancestry counts are in the final handoff; this historical observation is not a fresh synchronization permission. No merge/cherry-pick/reset/rebase/force-push is authorized by this reference.

Any later delivery entry must record date, task, approved scope, starting/implemented/accepted SHA separately, concrete changes, test command/environment and exact outcomes, evidence classification, acceptance owner/decision, and still-open gates. Preserve earlier runs and explain which new result supersedes which earlier issue; do not sum overlapping batches, invent unrecorded acceptance, treat developer evidence as independent execution, or promote SQLite evidence to MySQL concurrency PASS. Normative policy changes belong in §16 with their authority; implementation/acceptance history belongs in §17–18.

**Still OPEN / NOT_RUN:** MySQL financial concurrency, production MySQL migration/rollout, AssabAPP integration/release, Dashboard integration/release. These are not reopened S1-10 acceptance blockers. This documentation update starts no product stage and closes no release gate.

## 19. S1-11 Corrective Decisions and Current Authorization — 2026-10-11

### 19.1 Evidence and acceptance boundaries

Mahmoud reviewed Phases 1–7 at `b854e37044d1b81a08faafbc0e9e6ac700fd006a`: AUD-01/02/03/04/06/07 PASS at source-inspection level; AUD-05 FAIL (P1). This is **NEEDS CORRECTION**, not formal acceptance of all S1-11. The following requirements are mandatory corrections. Local implementation or passing tests do not establish Mahmoud's subsequent acceptance.

Mahmoud subsequently explicitly authorized completing through Phase 10 on top of that remote SHA plus the latest AUD-05 correction, without losing published Phases 1–7 changes. Use normal commits on `task/s1-11-corrections-and-liability`; no overwrite, force push, Foundation merge or main merge. This later authorization supersedes his earlier Phase 8 sequencing prohibition; it does not resolve financial authority or authorize production activation.

### 19.2 Proven legacy shortage liability — AUD-05A/B/C

- First-time automatic discovery MUST exclude pre-existing soft-deleted legacy variance rows. Deleted 100 followed by a live replacement 120 contributes **120**, never 220.
- A previously deterministically linked live shortage row, soft-deleted later, MUST retain its immutable contribution to effective posted history. Do not delete or rewrite historical rows or existing immutable links.
- Multiple effective candidates or an uncertain replacement/link relationship MUST fail closed; do not sum ambiguous replacements.
- The legacy manager `PersonalLedgerTransaction` labelled `Variance from Cashier` is the custody counterpart of cashier liability. It MUST NOT seed manager liability. Cashier shortage 100 plus its manager custody mirror produces cashier liability 100 and manager liability 0; no synthetic manager charge/reversal.
- Legacy cashier `Variance` with `is_cash_in=true` is surplus. It MUST NOT seed negative shortage liability or trigger a compensating positive employee charge. Only proven historical SHORTAGE LIABILITY can seed the effective-before amount.

These clarify BR-17/BR-18 and preserve the separation of physical custody, branch surplus and employee liability.

### 19.3 Correction and daily close contracts

- Same-recipient correction retains the original request identity. A later physical presentation gets a new attempt identity. Different-recipient replacement cancels/supersedes the old request with reciprocal lineage; pending retained cash must first be returned and confirmed.
- Public financial/revision-changing corrections require scoped durable idempotency and an explicit current `expected_revision`. Lock and reread current state; stale commands return `409 STALE_REPORT_REVISION`. Identical retries return the stored successful response; changed payloads using the same key return `IDEMPOTENCY_PAYLOAD_MISMATCH`. A lost response must not reapply revision/history/financial effects.
- Monetary correction evidence uses `field_type=monetary_halalas`; `old_halalas/new_halalas` remain integer halalas. Other evidence types may be `string`, `integer` or `json`. This changes metadata, not monetary values.
- Post-finalization reopen remains disabled while D-PR is unresolved. Submitted/finalized resources and available actions expose `can_reopen=false`; direct reopen remains blocked with `409 REPORT_REOPEN_REQUIRED`, without history/DB mutation. Historical reopen metadata remains intact. No reopen role is inferred.
- Daily submit requires an explicit manager report revision and durable required idempotency. Its server-derived scope is the actual branch/workday membership, including evidenced unconsumed carryovers, excluding future, foreign and unrelated historical reports. Summary, required receipts/counts, liability locks and daily sweep must agree.
- Missing required revision/count/receipt/provenance evidence fails closed. Evidence is not manufactured from receipts or sales declarations. Report creation/correction and daily submission share the same transaction boundary; failure rolls back submission, sweep, locks, bridge and successful response storage together.

### 19.4 Approval and linked adjustments

Current-revision shortage allocation/confirmation and assigned-manager approval are distinct from a responsible employee accepting/objecting to their own share. The employee's objection is recorded evidence, not an approval veto. Resolve an authenticated employee from server-owned identity links; clients cannot select another responding actor. Accountant corrections still cannot change gross sales and require manager review even when the corrected variance is zero.

Already posted shortage remains effective while replacement approval is pending. On approval reconcile only the signed difference against proven effective history, once per scoped operation, preserving immutable lineage and both custody/employee posting semantics. An allocation supersession alone is not permission to reverse a posted charge.

Confirmed receipt adjustments are separate linked records with their own version and idempotency contract. Original receipt amount, report count and original ledger rows remain immutable. Validate historical source/recipient/branch/company/attempt provenance and recorded available cash after active reservations under shared owner locks. Prevent duplicate spending across simultaneous adjustments.

**Settlement business authority remains unresolved.** The public writer and service fail closed with `403 SETTLEMENT_AUTHORITY_UNRESOLVED` by default. An assigned manager policy in technical tests is a proposed adapter, not a granted production permission. Explicit test-only authorization simulations prove accounting behavior; they do not approve or activate Phase 9 financially.

### 19.5 Verification and publication

Run tests for the changed paths and their affected dependencies, then one bounded combined regression set; do not rerun the entire project after each edit. Each detected defect needs reproduction, a correction and a fresh verification result. MySQL concurrency and existing populated-schema upgrade require actual MySQL evidence; SQLite cannot substitute. Upgrade checks apply new additive migrations normally, without rebuilding the existing baseline or rewriting historical migrations.

Record fresh syntax, Pint and diff checks and actual suite identities/counts in the published S1-11 verification document. Historical counts below remain historical. AssabAPP compatibility, production readiness, upstream integration and Mahmoud's final acceptance remain separate gates. This edition preserves version 2.0 and BR-01–BR-25/AC-01–AC-21 identifiers.


### 19.6 Final integration review corrections

- A corrected shortage allocation's numeric revision token must deterministically bind to the current physical evidence UUID for that same source report. The compatibility reader preserves source, company, branch, variance, allocation version and supersession validation. Historical allocations are not rewritten. This applies to responsible-party response and daily close, including nonzero corrections.
- Spendable cashier cash reconciles declared sales with the current immutable physical observation once per source report. Confirmed opening and pending incoming are excluded from that report's own cash contribution; receipt and subsequent settlement movements remain additive. Superseded counts do not contribute again. A required recount prevents use of invalidated cash evidence. Liability debits/credits are not proof of physically received cash.
- Cashier request creation and recipient replacement require unreserved cash under the same owner mutex as settlements. The reservation being replaced is excluded once. Increasing a same-recipient reservation requires funding; decreasing it does not consume additional cash. Legacy corrections that require an explicit count must still complete the count/presentation gates.
- Public bulk correction/daily commands acquire the complete relevant cashier owner set before workday/source/report locks. This preserves the order used by standalone accountant correction and settlement rather than introducing a workday-to-owner lock cycle.

Current implementation, exact existing tests, genuine MySQL races, populated existing-schema upgrade and quality checks are recorded in the canonical repository verification and coverage documents. Passing technical tests do not resolve settlement authority, D-PR or Mahmoud's independent acceptance.
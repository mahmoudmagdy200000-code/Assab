# S1-11 Business Rules v3.2 traceability (2026-10-11)

Evidence labels: SRC = source inspection; TEST = test exists and was executed green in this pass (see `s1-11-final-test-evidence.md`); NOT_RUN.

## A. S11 decisions NEW-01..NEW-08

| ID | Rule (Mohamed, v3.2) | Status | Basis | Blocks S1-11? | Blocks S1-12? |
|---|---|---|---|---|---|
| NEW-01 | Direct handover to next cashier or to manager | **Implemented** | `HandoverService::recordHandover` (`handover_to_type` cashier / branch_manager); recipient confirms. SRC + TEST (`ShiftTransferReceiptTest`, manager path) | No | No |
| NEW-02 | Substitute accepts, then starts at actual work receipt; start ≠ cash receipt | **Implemented** | S1-10 F01 operational responsibility; `CountedReassignmentGuard` is not a start gate. TEST (`ShiftOperationalResponsibilityTest`, `ShiftReassignHandoverCountTest`) | No | No |
| NEW-03 | Authorized cashier expenses via existing screens | **Deferred (feature absent, not unsafe)** | No cashier-actor expense path in Shift/Expense. Gap OG-01 | No (carry-over) | No |
| NEW-04 | Personal balance vs branch expense custody | **Partial** | Sales-cash custody vs personal ledger separated; expense-custody tie depends on NEW-03. Gap OG-02 | No | No |
| NEW-05 | Dues repayment by normal handover to manager (partial allowed) | **Deferred (feature absent, not unsafe)** | No dues-reduction path. Gap OG-03 | No | No |
| NEW-06 | Accountant → Head of Accounts final close, with return cycle | **Substantially present; finality fail-closed** | `OperationService` approve → `finalApprove` (`STATUS_FINAL='final-approved'`) → `returnForReview`; `ShiftReportMutationGuard` and reopen blockers honour final-approved. Return-to-branch-manager choreography only partially evidenced. Gap OG-04 | No | No |
| NEW-07 | Receipt correction (48→480): reverse then re-record, linked, no second physical movement, not on finally-closed report | **Deferred, fail-closed** | Generic settlement writer disabled (`SETTLEMENT_AUTHORITY_UNRESOLVED`); confirmed receipts immutable (`CONFIRMED_RECEIPT_IMMUTABLE`). Gap OG-05 | No | No |
| NEW-08 | Two-decimal rounding, no tolerance | **Implemented** | `ShiftFinancialCalculator` integer-halala half-up residual VAT; unit tests green | No | No |

Impact on S1-11: none of the deferrals introduces a financial-safety defect; each is absent or fail-closed. Impact on S1-12 (session refresh): none; no dependency.

## B. Core invariants exercised by the corrected tests

| Rule | Where proven (TEST) |
|---|---|
| Accountant correction is final for liability; no manager review opened (S111-FINAL-01) | `ShiftAccountantCorrectionHttpTest`, `ShiftCashCountHttpTest::test_accountant_split…` |
| Documented pending transfer allows daily submit; otherwise `PENDING_TRANSFER_EVIDENCE_INCOMPLETE` / `TRANSFER_RECEIPT_STATE_MISMATCH` (DAY-03) | `ShiftLiabilityServiceTest`, `ShiftDailyCloseSubmitGateTest` |
| Rejected cash is physically returned and sender-confirmed before the same request is corrected (S111-FINAL-03) | `ShiftS111FinalCorrectionsTest`, `ShiftTransferReceiptTest`, `ShiftRejectionCorrectionTest`, `ShiftRejectionCompatibilityTest` |
| Correction path accepts the full report and revises in place; no reopen (S111-FINAL-05) | `ShiftLegacyFinalRejectionCorrectionTest`, `ShiftS111FinalCorrectionsTest` |
| No fixed rejection-count lockout (BR-17) | `ShiftRejectionCompatibilityTest::test_multiple_rejections…` |
| Cashier cannot hand over cash not held in custody | `ShiftReassignHandoverCountTest` (fixture now posts the custody row) |
| New presentation → new immutable attempt; confirmation bound to the current attempt | `ShiftTransferReceiptTest` (`expectedAttemptId`) |
| FIN-01 (115/50/25/10/30 → net 100, VAT 15, expected 50, variance −20, shortage 20) | `ShiftFinancialCalculatorTest`, `ShiftCashCountHttpTest` (unchanged) |

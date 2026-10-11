# S1-11 Current Coverage Matrix — 2026-10-11

Branch: `task/s1-11-corrections-and-liability`.
Baseline: `b854e37044d1b81a08faafbc0e9e6ac700fd006a`; preserved by ancestry. AUD-05 correction: `72301848df291c78cc3ddaad09aef294757e24a6`.

This is implementation and developer verification evidence for independent review. It is not Mahmoud acceptance, a Foundation/main merge, production activation or client release. Final run results are in [verification](2026-10-11-s1-11-phases8910-verification.md). Earlier NOT STARTED statements describe their dated historical checkpoint.

## Requirements and actual implementation

Test names below are existing classes under `tests/Feature`. Each row identifies coverage; engine claims belong to the separately recorded run, not to every method in a class.

| Requirement | Writer / reader and public contract | Actual regression evidence |
|---|---|---|
| T01 Same recipient | `HandoverService::recordHandoverEdit`; `ShiftTransferAttemptService::present`; same request, next presentation creates new attempt. `POST /api/v1/cashier/shifts/{shift}/handover/edit` requires key and expected_revision | ShiftRequestSameRecipientCorrectionTest; ShiftCorrectionCommandIdempotencyTest; ShiftCheckpoint1CorrectionTest |
| T02 Different recipient | `TransferRequestLifecycleService::replaceRecipient`; `POST /api/v1/cashier/shifts/{shift}/handover/replace-recipient`; source-bound reciprocal replacement/cancellation, return confirmation first | ShiftRequestReplacementTest; ShiftRequestCancellationGuardTest; ShiftRequestReservationTest; ShiftTransferPhysicalReturnTest |
| T03 Repeated/legacy rejection | `HandoverService`; immutable history, corrected legacy rejected_final can explicitly recount; confirmed receipt remains immutable | ShiftLegacyFinalRejectionCorrectionTest; ShiftRejectionCompatibilityTest; ShiftRejectionCorrectionTest; ShiftPhase1RemainingAuditTest |
| T04 Revision/idempotency | Required transactional middleware on financial corrections, liability commands and daily submit; successful exact-response replay, canonical mismatch and stale rejection | ShiftCommandIdempotencyHttpTest; ShiftCorrectionCommandIdempotencyTest; ShiftManagerCorrectionIdempotencyTest; ShiftLiabilityApprovalHttpTest; IdempotencyCommitRecoveryTest |
| T05 Accountant corrections | `AccountantReportCorrectionService`; `POST /api/v1/accountant/shifts/{shift}/corrections`; authorization, immutable gross sales, money in integer halalas and unchanged original count | ShiftAccountantCorrectionAuthorizationTest; ShiftAccountantReportCorrectionTest; ShiftAccountantCorrectionHttpTest |
| T06 Old approval invalidation | New revision/allocation supersedes prior approval; `ShiftReportManagerReviewService::requireFor`; no daily submit until current correction reviewed | ShiftAccountantCorrectionApprovalTest; ShiftDailyCloseSubmitGateTest; ShiftS111CompleteWorkflowTest (nonzero correction included) |
| T07 Zero-shortage review | Zero still requires explicit manager acknowledgment. `POST /api/v1/branch-manager/workday/shifts/{shift}/review-variance`; never automatic approval | ShiftAccountantReportCorrectionTest; ShiftS111CompleteWorkflowTest zero-shortage scenario |
| T08 Notifications/history | Additive correction evidence, preserved snapshots and after-commit notification/outbox behavior; no notification before commit | ShiftReportCorrectionNotificationsTest; ShiftReportCorrectionEvidenceTest; ShiftReportHistoryPreservationTest; ShiftPhases567MySqlConcurrencyTest |
| T09 Stable delta lineage | `ShortagePostingService`, `ShiftLiabilityLegacyPostingLinkService`; old posted effect remains pending review, approved replacement posts only the delta | ShiftShortagePostingTest; ShiftLiabilityLegacyPostingTest; ShiftAud05LegacyDiscoveryTest; ShiftAccountantCorrectionApprovalTest |
| T10 Retry once | Scoped operation/current allocation lock and immutable posting lineage; canonical key mismatch cannot overwrite an earlier result | ShiftShortagePostingIdempotencyTest; ShiftShortagePostingApprovalIntegrationTest; ShiftPhases567MySqlConcurrencyTest |
| T11 Linked settlement | `HandoverSettlementService`; `POST/GET /api/v1/branch-manager/handover-receipts/{receipt}/settlements`; immutable original + additive dual counterpart. Writer default 403 until authority resolved | ShiftHandoverSettlementSchemaTest; ShiftHandoverSettlementAuthorizationTest; ShiftHandoverSettlementServiceTest; ShiftHandoverSettlementHttpTest; ShiftHandoverSettlementBalancesTest |
| T12 Reopen | `ShiftReportReopenService` and supersession history for permitted in-flight correction. Finalized `/daily-close/reopen` remains 409 REPORT_REOPEN_REQUIRED; capabilities false | ShiftReportInFlightReopenTest; ShiftDailyLockSupersessionTest; ShiftCheckpoint1CorrectionTest |
| T13 Returned cash recount | `ShiftTransferPhysicalReturnService`, count invalidation and fresh-count guard; pending custody is not confirmed opening | ShiftTransferPhysicalReturnTest; ShiftReturnFinalizationGuardTest; ShiftCashCountHttpTest |
| T14 Daily stale approval | Shared `ShiftDailyCloseScopeService`, count-backed `CashCountLiabilityEvidence`, `DailyLiabilityGuard`; `POST /api/v1/branch-manager/workday/daily-close/submit` requires key/expected_revision | ShiftDailyCloseScopeTest; ShiftDailyCloseSubmitGateTest; ShiftPhases8910MySqlConcurrencyTest |
| T15 Independent start | `CashierShiftStartService::startShift`; operating responsibility ends only on actual successor start, financial ownership preserved | ShiftOperationalResponsibilityTest; ShiftS111CompleteWorkflowTest; ShiftPhases234IntegrationTest |

## Cross-cutting audit closures

- CP1-F01: same-recipient key/replay and explicit stale revision; source identity preserved.
- CP1-F02: finalized reopen unavailable in resource and command while D-PR unresolved.
- CP1-F03: monetary correction `field_type=monetary_halalas`, values remain integer halalas.
- AUD-05A: first discovery excludes pre-deleted rows; immutable already-linked live row survives later source deletion.
- AUD-05B: manager Variance from Cashier is custody mirror, not manager liability.
- AUD-05C: surplus never seeds shortage liability; ambiguity fails closed.
- F01–F13: exact findings and implementation evidence are in [finding register](2026-10-11-s1-11-phases8910-findings.md).
- Final independent review: nonzero numeric/UUID revision binding, current physical cash reconciliation, cashier reservation funding and public bulk owner-before-workday order received separate regressions and fixes.

## Limits

Settlement positive tests explicitly simulate an approved policy in a disposable test process. They do not resolve the actual authority decision. D-PR, Mahmoud final acceptance, AssabAPP/Dashboard compatibility and deployment remain separate open gates. Whole-repository 1,550-test runs are not repeated: run changed/affected tests and the final affected union. MySQL concurrency and populated existing-schema upgrades are separate proofs from SQLite.
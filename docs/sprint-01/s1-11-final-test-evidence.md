# S1-11 final test evidence — correction pass (2026-10-11)

All runs: PHP 8.3.6, PHPUnit 12.4.0, ParaTest, SQLite `:memory:` (testing env), working tree = `f62a4ba` + this pass's test/doc changes. Executed by the correction-pass author in the author's own session (evidence class **DEVELOPER_EXECUTED / AUTHOR_REPORTED**; this is not independent execution, and the independent re-audit must re-run at the exact SHA). No JUnit artifact or runner log is stored in the repository: `/tmp/full-corr.xml` below was a temporary path.

## 1. Baseline before the pass (audit f62a4ba)
1890 tests / 10573 assertions / 9 failures / 3 errors / 33 skipped (12 red = 10 stale by `f62a4ba` + 2 pre-existing; proven by re-running parent `8fe1daaa`).

## 2. Full regression after the pass
```
php -d memory_limit=-1 vendor/bin/paratest -p4 --passthru-php="'-d' 'memory_limit=-1'" --log-junit /tmp/full-corr.xml
```
**Exit 0 — 1890 tests / 10666 assertions / 0 failures / 0 errors / 33 skipped**, 13:46 wall.

Skipped (33): `ShiftPhases234MySqlConcurrencyTest` 10, `ShiftPhases567MySqlConcurrencyTest` 12, `ShiftPhases8910MySqlConcurrencyTest` 10 (MySQL-only, **NOT_RUN**), `BranchFixedAssetsUploadPersistenceTest` 1 (SQLite does not enforce column length; same skip as the S1-10 record).

## 3. The 12 formerly red tests
| Test | Root cause | Change | Real assertion kept? |
|---|---|---|---|
| `ShiftAccountantCorrectionHttpTest` ×2 (`…successful_and_idempotent`, `…company_me_alias…`) | S111-FINAL-01: `manager_review_required` now false | expect `false` | Yes |
| `ShiftCashCountHttpTest::test_accountant_split_of_a_counted_shortage_is_final_without_manager_review` (renamed from `…_is_refused`, which no longer described the behaviour) | allocation now `approved`; no pending manager review | expect `approved`; assert zero pending reviews (replaces "review exists with −2000") | Yes (variance −2000 still asserted via allocation/payload) |
| `ShiftLiabilityServiceTest::test_required_transfer_blocks_daily_even_after_liability_approval` | DAY-03 renamed gate | expect `PENDING_TRANSFER_EVIDENCE_INCOMPLETE` | Yes |
| `ShiftTransferReceiptTest` ×2 (`…corrected_and_then_confirmed_exactly`, `…input_error_correction…`) | S111-FINAL-03/05: return step, full report, new attempt | present → reject(attempt) → return → sender confirm → edit with report fields → present attempt 2 → confirm with `expectedAttemptId` | Yes (old/new amount, evidence rows, receipt, ledger count unchanged) |
| `ShiftRejectionCorrectionTest` ×2 | same | `d11Reject()` now returns the cash; accept carries `transfer_attempt_id`; plain-reject code `PHYSICAL_REJECTION_DETAILS_REQUIRED` | Yes (409 + zero writes; recon 0 / opening 480) |
| `ShiftRejectionCompatibilityTest::…br17` | same | each of 3 rejections presents, rejects with attempt, returns | Yes (no lockout, counts 1/2/3) |
| `ShiftLegacyFinalRejectionCorrectionTest` | S111-FINAL-05: no reopen | status stays `COMPLETED`; count supplied with the correction | Yes, except one intermediate assertion moved (see handoff) |
| `ShiftReassignHandoverCountTest` ×2 | **stale fixture**: no custody row | seed `Total Sales` custody row | Yes, unchanged |

## 4. Targeted runs (each exit 0)
ShiftReassignHandoverCountTest 29/230 · ShiftCashCountHttpTest 28/285 · ShiftLiabilityServiceTest 28/68 · ShiftAccountantCorrectionHttpTest 8/28 · ShiftTransferReceiptTest 51/323 · ShiftRejectionCorrectionTest 10/71 · ShiftRejectionCompatibilityTest 11/106 · ShiftLegacyFinalRejectionCorrectionTest 1/25 (tests/assertions). `vendor/bin/pint --test` run on changed test files; style issue fixed.

## 5. NOT_RUN
- **MySQL transactional / concurrency (32 tests)** — NOT_RUN: no MySQL/MariaDB binary and no reachable package source in this environment. Must be run by Mahmoud in an isolated MySQL before any release.
- AssabAPP, Dashboard integration — NOT_RUN / out of scope.

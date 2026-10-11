# S1-11 AUD-05 final correction verification

Date: 2026-10-11. Repository: `mohameelsherbini/Assab`.
Publication target: `task/s1-11-corrections-and-liability`.
PREVIOUS_SHA: `b854e37044d1b81a08faafbc0e9e6ac700fd006a`.

This correction is based directly on the reviewed remote commit. It does not change Foundation/main or claim Mahmoud's final acceptance. The latest user-relayed Mahmoud authorization permits subsequent Phases 8–10 reconciliation and publication; that is a separate commit scope.

## Findings

- **AUD-05A:** First discovery includes only live legacy cashier SHORT entries. Pre-existing soft-deleted replacements are excluded. Existing immutable links remain effective after later source deletion. Multiple live entries for an actor, or a new replacement after an immutable prior link, require explicit reconciliation rather than guessing an additive amount.
- **AUD-05B:** `Variance from Cashier` is a manager custody mirror. Automatic discovery does not link it to manager liability. The regression invokes the authoritative legacy listener and proves that the cashier shortage contributes 100 SAR while the manager mirror and manager liability remain unchanged/zero.
- **AUD-05C:** Cashier surplus (`Variance`, `is_cash_in=true`) is excluded from automatic shortage discovery. Zero modern shortage produces no liability link or positive charge.

Original ledger entries and existing immutable links are never edited or deleted by this correction. Employee discovery semantics and approved posting targets remain unchanged.

## MySQL consistency repair

A new bounded two-process race reproduced duplicate legacy discovery after the second transaction waited for the source-shift mutex. Its outer MySQL REPEATABLE READ transaction had already established an old snapshot. Discovery, existing links, shares, posting states and effective posted history now use current locking reads under that mutex. The race proves one live 12 SAR link, one -2 SAR reconciliation to the 10 SAR approved target, zero second posting, and an unchanged manager custody mirror.

## Executed verification

Runtime: PHP 8.4.26 / PHPUnit 12.4.0. Tests load this isolated checkout's autoloader through an external runtime wrapper; dependencies and credentials are not committed.

| Gate | Actual result |
| --- | --- |
| SQLite affected functional set | PASS: 26 tests, 153 assertions, 0 errors/failures/skips |
| MySQL affected functional set | PASS: 26 tests, 153 assertions, 0 errors/failures/skips |
| MySQL new AUD-05 race | PASS: 1 test, 13 assertions |
| MySQL existing approval and redistribution races | PASS: 2 tests, 39 assertions |
| PHP syntax | PASS: all 6 changed/new PHP files |
| Pint | PASS: all 6 changed/new PHP files |
| `git diff --check` | PASS |

Functional files: `ShiftAud05LegacyDiscoveryTest`, `ShiftLiabilityLegacyPostingTest`, `ShiftShortagePostingTest`, `ShiftShortagePostingIdempotencyTest`, `ShiftShortagePostingApprovalIntegrationTest`.

Concurrency methods in `ShiftPhases567MySqlConcurrencyTest`: `test_aud05_concurrent_first_discovery_links_only_live_shortage_once`, `test_concurrent_manager_approvals_with_shortage_are_idempotent_or_reject_duplicate`, and `test_redistribution_vs_posting_actual_race_and_union_consistency`.

MySQL engine: local MySQL 8.4, 127.0.0.1:3310. Owned disposable schemas: `s111_phase567_test_aud05_final_codex` (functional) and `s111_phase567_test_aud05_codex` (concurrency). Full application suite was not run, following the affected-test policy.

Initial regressions reproduced the deleted-row, manager-mirror, surplus and replacement ambiguity defects. The first race reproduced a unique-key failure; after the source repair, a fixture comparison was corrected to compare persisted attributes before/after (including database defaults and driver boolean representation). All listed final checks passed after those repairs.

Remote branch was rechecked at `b854e37044d1b81a08faafbc0e9e6ac700fd006a` before the local corrective commit. Push is deferred until the authorized Phases 8–10 final verification. Status: **AUD_05_READY_FOR_FINAL_REAUDIT**.

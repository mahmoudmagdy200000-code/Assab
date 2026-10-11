# S1-11 Phases 5–7 findings and correction record

Date: 2026-10-11.
Base: `188bc53e0a05cb4b5f7eccb89c5fa6d3eaff7ff2`.
Branch: `task/s1-11-corrections-and-liability`.

## Implementer handoff

Antigravity implemented the Phase 5 accountant correction/review/outbox paths, Phase 6 bulk correction integration, and Phase 7 additive liability posting/legacy link paths. Its original detailed findings register and verification report are preserved in the local evidence directory before this correction pass. Implementer test results are distinct from independent review and formal acceptance.

## Verified corrections

| ID | Gap / root cause | Correction and evidence |
| --- | --- | --- |
| C567-01 | The forced-deadlock test added a retry wrapper itself and threw before real application writes. It could pass while the services did not retry. | Injected failure after real correction evidence/outbox callback registration, and separately after actual approval financial writes. Correction and approval services now have bounded transaction retries. Tests assert rollback IDs disappear, one committed callback, exact evidence/outbox/inbox counts, and one final financial effect. |
| C567-02 | A nested correction service cannot own retry when required idempotency owns the outer transaction. Laravel also renders an inner deadlock as an HTTP response. | The accountant correction's command transaction retries at the middleware boundary; it restores a rendered DeadlockException for rollback/retry. HTTP regression proves successful retry and exact stored-response replay with no second correction. Other command retry policies are unchanged. |
| C567-03 | The daily boundary worker copied only part of submission logic. | Worker invokes the actual submitDailyReport controller. The race verifies submission success, daily_closed_at on the included handover, one bridged sales operation, and rejection of waiting correction with REPORT_REOPEN_REQUIRED. |
| C567-04 | Redistribution net amounts were checked from posting records rather than the actual signed custody ledger. | Test verifies A=10/B=0 while pending, original A debit10, A credit4, B debit4, then signed actual ledger nets A=6/B=4 after approval. |
| C567-05 | Upgrade baseline incorrectly excluded CP1 migration 000010 and the report invented a legacy-links migration name. | CP1 normalization remains in baseline; only 000006/000007/000008 are pending. Fresh populated MySQL upgrade preserves prior IDs/values. Probe refuses to drop populated schemas and uses explicitly supplied database settings. |
| C567-06 | PHP discovery through git status omitted files inside an untracked directory. | Recursive Git file discovery includes all 55 modified/new PHP files. All pass syntax and Pint checks. |

## Limits

The scoped independent review found no blocking issue in Codex's corrections; it did not run database tests or audit all Phase 5–7 code. Fresh execution results are in `2026-10-11-s1-11-phases567-codex-corrections-verification.md`. No project Phases 8–10, Foundation changes, main changes, upstream PR, or formal acceptance are part of this delivery.

# S1-10R — approved operational responsibility and physical-return correction

Mahmoud approved this bounded additive design on 2026-10-09 after reviewing the F01/F02 state/schema stop. Review base: `e5907558148f2cd3cdf5db90d5a6ab8d622a4854`; working branch: `review/s1-10-rule-reconciliation`, personal fork `mohameelsherbini/Assab`. No reset, history rewrite, sprint merge/cherry-pick or S1-11 start is authorized. Phase 2 remains **NOT ACCEPTED** pending review of the submitted correction SHA.

## F01 — separate operational intervals from financial reports

The approved additive fields on `cashier_shifts` are nullable/indexed UUID `operational_chain_id` and nullable timestamp `operational_ended_at`. Existing `actual_start_time` remains the actual operational start. The chain represents a reassignment stream; it is not a permanent physical-till entity. There is no approved till/register/drawer redesign.

Assignment and acceptance do not end the predecessor's responsibility. At the replacement's actual start, one server timestamp ends the predecessor's operational interval and starts the replacement's interval atomically. Current ownership is scoped to the operational chain, with deterministic transaction-local locks, fresh identity/state checks and retry protection.

Operational transfer must preserve report ownership, count, revision, allocation, approval and evidence. It must create no receipt, opening, liability/custody movement or financial close. Financial `ShiftStatus`, `actual_end_time`, `ShiftEndedEvent` and `ShiftCloseService` must not represent operational interval termination. The predecessor retains the existing financial submission/correction rights.

The legacy operational state is authoritative. Admin mirrors are eventually consistent projections: a cashier mirror linked to a legacy row with an ended operational interval must not appear as currently live/late. Preserve financial mirror status and eligibility for the predecessor's eventual report close. A best-effort projection failure must not undo a committed operational start; it must remain observable and recoverable.

## F02 — attempts and explicit sender-confirmed returns

A request and a physical presentation are distinct identities. A physical redelivery on a corrected request receives a new immutable attempt, separate from the report revision. Preserve the request/revision, original sender/recipient, historical source branch/company where available, presented amount in halalas and sequence/timestamp at attempt creation. Do not manufacture historical context from a cashier's current branch/company.

The recipient initiates a return from an exact attempt; only the original sender's confirmation establishes physical return. No manager/system override, elapsed-time confirmation or inference from rejection/text/upload is authorized. Preserve rejection evidence, request history and confirmed receipts. Return identities and payload checks must prevent repeated financial/disposition effects.

For the same attempt, retained physical cash equals counted rejected cash less confirmed returns. Initiated but unconfirmed returns do not reduce retained cash. Old returns must not suppress later deliveries. Confirmed receipts remain the only opening-cash source; return of unconfirmed rejected cash must not reverse a custody movement that never existed.

Receipt/return decisions serialize on the authoritative request/attempt state, with consistent lock order and fresh actor/recipient/state checks. Neither a stale receipt nor a stale return may consume an incompatible possession state.

If physical return changes possession after a receiving report was counted and the existing lifecycle permits correction, preserve the old revision/count/allocation/approval evidence, advance to an uncounted revision and require a fresh physical count. Do not carry forward stale count or silently recalculate/repost liability. If an immutable/final daily boundary prevents this, return **REPORT_REOPEN_REQUIRED** without reopening history; orchestration remains S1-11.

Historical records lacking a safely mapped attempt retain nullable legacy identity and their existing behavior. New typed behavior applies prospectively; no invented legacy return or backfilled physical-delivery facts.

## Prospective API contract

These authenticated Shift routes are available under the existing `/api` and `/api/v1` mounts. The new explicit amount fields are integer **halalas**; existing legacy monetary fields retain their existing SAR contract.

| POST path | Authorized actor | Payload / effect |
|---|---|---|
| `shift-transfers/{handover\|manager_transfer}/{requestId}/present` | Original source cashier/manager | `presented_halalas`, `idempotency_key`; record an actual physical presentation, not request creation. |
| `shift-transfer-attempts/{attemptId}/reject` | Exact attempt recipient | `physical_halalas`, `reason`, `correction_reason`; preserve the measured rejection against that attempt. |
| `shift-transfer-attempts/{attemptId}/confirm-receipt` | Exact attempt recipient | `confirmed_halalas`; invoke the existing receipt writer for this attempt. |
| `shift-transfer-attempts/{attemptId}/returns` | Exact attempt recipient | `returned_halalas`, `reason`, `idempotency_key`, optional `evidence_reference`; initiate only, with no possession reduction. |
| `shift-transfer-returns/{returnId}/confirm` | Original sender | Append sender confirmation once; no override or evidence inference. |
| `shift-reports/{cashierShiftId}/recount` | Report-owning cashier | `counted_halalas`; provide a fresh physical count after explicit invalidation. |

When using the existing receipt or amount-correction rejection endpoints on a typed request, pass matching `transfer_attempt_id`. A missing/old ID conflicts rather than attaching a delayed command to the latest attempt. Untyped historical requests keep the original contract. Idempotency keys on presentation and return initiation are scoped to the request/attempt, and payload mismatch conflicts. Sender-confirmation retries return the established fact without another revision or disposition effect.

Redelivery requires a new presentation after the prior attempt's held cash has been returned; correction alone cannot confirm an old rejected attempt. A legacy rejection with no safe attempt identity cannot be upgraded from current participant/branch data. Retain its history and existing pending reader behavior.

`PHYSICAL_RECOUNT_REQUIRED` blocks report handover, financial close/finalization and manager daily submission while the receiving report needs a fresh count. Existing approved carry-over reports outside the recent seven-day window are included in the daily guard. Previous counts and allocation/approval facts remain historical; recount does not silently post/reverse liability. The existing allocation evidence checks govern any resulting shortage.

`REPORT_REOPEN_REQUIRED` protects a submitted workday, a swept handover and a closed/pending-review Admin pipeline. The existing pipeline must first legally leave its immutable/review boundary; this correction does not orchestrate rejection/reopening or accept an old SHF payload as a new count. Manager receiving-workday checks use the rejection day rather than the earlier presentation day.

## Scope and evidence

New additive migrations are authorized only for operational-chain fields and attempt/return facts. Preserve e5907558 report separation, R1 timeout reminders, C2 historical attribution, C3 locking, C4 cleanup, FIN-01, D14/D17/D18, receipts and idempotency. R5 accountant correction, R4b general recipient replacement, R6 post-receipt adjustment, full daily reopen and AssabAPP/Dashboard changes remain outside scope.

Label evidence **DEVELOPER_EXECUTED**, **SOURCE_INSPECTION_ONLY** or **NOT_RUN**. SQLite is not MySQL concurrency evidence. **MYSQL CONCURRENCY = NOT_RUN** until verified with independent MySQL connections. Final implementation contract and fresh validation results must be recorded before publication.

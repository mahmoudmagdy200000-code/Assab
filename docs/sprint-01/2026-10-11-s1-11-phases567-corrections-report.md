# S1-11 Phases 5–7 — Audit Corrections and Verification

**Date:** 2026-10-11

**Audit base / isolated worktree HEAD:** `bf97dfe27bf308a373a76ff4a96a43b5447d0a4a`

**Scope:** Mahmoud's independent audit findings AUD-01 through AUD-07 for Phases 5–7.

## Status

Corrections and focused regression coverage are prepared in an isolated worktree for re-review. This report does not claim formal acceptance by Mahmoud.

| Finding | Correction status | Evidence added or updated |
|---|---|---|
| AUD-01: allocation completeness and independent approval validation | Addressed | Negative variance requires a complete allocation for the current report revision; approval checks current report/count/evidence, variance, scope, state, and exact shares. Zero or positive variance supersedes an active allocation without creating a synthetic zero allocation. |
| AUD-02: monotonic allocation versions and stale revision | Addressed | Version is derived from the latest historical allocation while holding the cashier-shift lock; stale expected versions are rejected. Coverage includes the zero-allocation gap and a bounded MySQL concurrent allocation attempt. |
| AUD-03: manager correction and liability review | Addressed | A financial manager correction creates a pending current allocation linked to the prior historical allocation, advances the report state, preserves count/receipt lineage, and requires manager review before posting. No cashier re-approval is introduced. |
| AUD-04: idempotency on public manager corrections | Addressed | Workday/end and daily-close corrections require a durable key only when a mutation occurs; same-key identical requests replay, changed payloads return `IDEMPOTENCY_PAYLOAD_MISMATCH`, and no-op requests preserve compatibility. |
| AUD-05: deterministic legacy liability links | Addressed | Exact cashier-shift, operation, actor, type, sign, and scope matching links eligible legacy rows before modern posting. Ambiguous or foreign rows fail closed; legacy rows are not changed. Tests cover 100→100, 100→120, and 100→60 outcomes. |
| AUD-06: admin shift-close mirror lookup | Addressed | The cashier mirror is resolved through exact `legacy_shift_id` and validated for branch, company, and cashier role; date/latest fallback is removed. |
| AUD-07: allocation supersession lineage | Addressed | Each new allocation stores `supersedes_allocation_id` for the latest historical allocation; prior allocation shares remain unchanged. |

## Verification

- Focused SQLite union after formatting: **PASS — 53 tests, 297 assertions**.
- Phase 5–7 MySQL bounded concurrency suite: **PASS — 10 tests, 145 assertions**.
- Phase 2–4 MySQL regression suite affected by shift mapping and correction shares: **PASS — 10 tests, 85 assertions**.
- PHP syntax: **PASS — 22 changed PHP files**.
- `git diff --check`: **PASS**.
- Pint: **PASS — all 22 changed PHP files**, confirmed in the authorized publication pass. The sandbox initially prevented temporary-file writes; the successful check ran with sufficient host permissions and a worktree-local `sys_temp_dir` override. No application or formatting changes were needed in this publication pass.
- The SQLite focused union, syntax, and diff checks were rerun for publication. The MySQL counts above are the earlier recorded results on this correction set, not a new MySQL execution in the publication pass.
- The full 1,550-test suite was not run; verification was limited to changed and affected tests, as requested.

## Boundaries and handoff

- Phase 8 was not started. No Foundation or `main` changes were made.
- The user explicitly authorized a standalone correction commit and push to the personal fork's `task/s1-11-corrections-and-liability` branch on 2026-10-11. Actual publication outcome and the full resulting SHA are returned in the handoff; no upstream PR or merge is authorized by this publication.
- Corrections are in `D:\claude\AssabERP\.tools\s111-audit-1-7`, based at the submitted SHA above. The primary checkout at `D:\claude\AssabERP\Assab` retains its pre-existing unpublished work for Phases 8–10; this correction set has not been merged into it.
- Before publication, the remote target was verified at `bf97dfe27bf308a373a76ff4a96a43b5447d0a4a`, matching the correction commit's parent. Publication uses a normal fast-forward push from the isolated worktree. The primary checkout's local branch and files remain at their earlier state; reconcile its unpublished work with the published correction commit before resuming development there.
- The correction commit contains only the audit fixes, their affected regression tests, and this report. The report and tests do not replace Mahmoud's independent acceptance or authorize Phase 8.

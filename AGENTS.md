# AGENTS.md — Assab ERP development instructions

These instructions apply to the repository root and all subdirectories, unless a more specific AGENTS.md adds compatible local guidance.

**Read [docs/ai-workflow.md](docs/ai-workflow.md) before working on any task.** That guide defines the full ChatGPT Work ↔ Codex Cloud workflow, approval gates, evidence requirements, and security/financial safeguards. Review the live repository and task records because documentation or historical SHAs may be stale.

## Repository and source of truth

- Development fork: `mohameelsherbini/Assab`.
- Original upstream (review/merge destination only): `mahmoudmagdy200000-code/Assab`.
- Sprint base: `sprint/01-financial-foundation`. Verify the actual HEAD and intended target on every task.
- Consult `docs/sprint-01/task-register.md`, `docs/sprint-01/verification.md`, the Sprint 01 v2.0 plan, and applicable approved technical decisions before implementation.
- Never confuse implementation, passing tests, development closure, reviewer recommendation, and **formal acceptance by Mahmoud at an identified SHA**.

## Required task sequence

1. **Analyze:** confirm repository, branch, full starting SHA, working-tree state, task scope, dependencies, acceptance criteria, and risk.
2. **Plan:** propose affected files, financial/API/security invariants, migrations, regression and concurrency test plan. Wait for explicit plan approval before changing project files.
3. **Implement:** use a dedicated task branch in the personal fork; work only within the approved scope. Preserve pre-existing work.
4. **Verify:** run safe targeted tests and broader checks as feasible; record commands, results, tested SHA or worktree state, failures, skipped tests, and environment limits.
5. **Review:** provide actual diff and evidence for independent ChatGPT Work review. If Work cannot access unpublished edits, supply a patch/log; do not pretend GitHub reflects unpushed changes.
6. **Publish only after separate explicit approval:** commit and push to **personal fork only**. An approval to implement or review is **not** permission to commit or push.
7. **Upstream PR only after another explicit approval:** prepare a comparison to the agreed base branch in Mahmoud's repository. Mahmoud handles acceptance and merge; do not merge on his behalf.

## Non-negotiable safety rules

- Never push directly to upstream, force-push, discard uncommitted work, reset shared history, or change branches destructively without explicit approval.
- Do not modify application code, task statuses, or documentation while instructed to analyze/review only.
- Do not commit secrets, credentials, ignored dependencies (`vendor/`, `node_modules/`), logs, or generated artifacts.
- Preserve transaction atomicity, once-only financial effects, receipt/liability separation, tenant/branch permissions, monetary precision, idempotency, and existing API compatibility.
- Treat SQLite and MySQL/MariaDB tests as different evidence. Do not mark concurrency/locking gates complete on SQLite evidence alone. Confirm the effective DB target before any destructive test or migration; use approved disposable schemas only.
- Respect open release gates such as AssabAPP compatibility, S1-11 required financial behavior, and pending-handover drain checks. A successful build does not authorize deployment.
- Stop and ask when the task conflicts with the current code, approved decisions, or git history.

## Mandatory end-of-task report

Report task ID; repository/branch; starting and ending HEAD; changed files and diff summary; tests actually executed with test/assertion/failure/error/skip counts; security and financial risks; unresolved blockers; and explicit `commit/push/PR` status. For read-only or implementation stages, finish without commit, push, PR, or merge unless specifically approved.

For the detailed templates and gate definitions, use **[docs/ai-workflow.md](docs/ai-workflow.md)**.

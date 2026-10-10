# Assab ERP — ChatGPT Work ↔ Codex Cloud Workflow

**Version:** 1.0  
**Owner:** mohameelsherbini  
**Status:** Proposed team workflow — review before merging into the Sprint branch  
**Scope:** Assab ERP development and verification; not an acceptance record.

## 1. Repository policy

| Role | Repository / branch |
| --- | --- |
| Personal development fork | `mohameelsherbini/Assab` |
| Original upstream | `mahmoudmagdy200000-code/Assab` |
| Sprint integration base | `sprint/01-financial-foundation` |
| Task branch | `task/<task-id>-<short-scope>` off a verified base |
| Workflow document | `docs/ai-workflow.md` |

All new development is conducted against the **personal fork**. Never directly push to the original upstream repository. Open an upstream Pull Request only after explicit user approval; Mahmoud reviews and accepts/merges upstream work. A local commit, successful test, PR or merge alone does not imply formal acceptance.

**Separate permissions:** plan approval, code-change approval, commit/push approval, PR-creation approval, and upstream acceptance are distinct gates. Never infer one from another.

## 2. Sources of truth and evidence precedence

1. **Actual Git state and code** on the named repository, branch and full SHA establish what exists.
2. **Sprint 01 v2.0 plan, approved technical decisions and contract docs** define intended scope and invariants, subject to newer explicit approved decisions.
3. `docs/sprint-01/task-register.md` records task status and Mahmoud's acceptance; always check the task's current row and dated updates rather than trusting stale introductions.
4. `docs/sprint-01/verification.md` holds test evidence. A historical run must not be described as freshly run or as verifying a later SHA.
5. Earlier ChatGPT project `assab-erb`, Work project `ASSAB ERP GITHUB`, and Codex transcripts provide decision context **only when accessible and verified**. Missing history must be explicitly reported.
6. Cross-repository contracts: inspect `dashboard` as required; `AssabAPP` is compatibility/reference scope only unless separately authorized.

Never invent test outcomes, a commit SHA, acceptance status, environment readiness, or unavailable context.

## 3. Responsibility matrix

| Stage | ChatGPT Work | Codex Cloud | User / Mahmoud |
| --- | --- | --- | --- |
| Intake and analysis | Retrieve current Git evidence; clarify scope, dependencies, constraints and risk | Optional read-only feasibility inspection | User confirms task intent |
| Plan | Produce task plan, affected files, test matrix, rollback/compatibility risks and acceptance criteria | Sanity-check implementation feasibility | User explicitly approves plan |
| Implementation | Track approved scope | Modify task branch; implement regression tests | User approves scope changes |
| Verification | Independently review diff and test evidence | Run targeted checks, full suite if feasible, and safe database checks | User evaluates blockers |
| Correction | Prepare precise fix request | Apply corrections and rerun checks | User approves next iteration |
| Commit / Push | Review proposed commit/diff, target and tested SHA | Commit and push **only to personal fork**, on explicit approval | User authorizes |
| Upstream PR | Verify base/head and PR diff; prepare reviewer summary | Assist if requested | User authorizes PR; Mahmoud reviews and merges |
| Handoff | Record final SHA, results, pending gates and next task | Report reproducible commands and artifacts | Mahmoud alone confirms formal acceptance |

ChatGPT Work and Codex Cloud **do not automatically share a filesystem or complete conversation history**. If changes are not committed/pushed, Work must be given a trustworthy patch/diff and logs; never claim a GitHub-only review includes unpublished Codex edits.

## 4. Task lifecycle and approval gates

`BACKLOG → ANALYSIS → PLAN APPROVED → IN PROGRESS → IN REVIEW → READY TO COMMIT → PUSHED TO FORK → PR OPEN → ACCEPTED / CHANGES REQUESTED`.

- **Gate A — Plan:** verify repo, branch, starting SHA, clean/dirty tree, task row, decisions, dependencies, scope, out-of-scope and explicit acceptance criteria. Stop for user approval.
- **Gate B — Implementation:** Codex works on a dedicated task branch in the fork. No destructive reset/force-push; preserve work and approved changes. No commit/push by default.
- **Gate C — Review:** Work reviews an actual diff, tests, failure cases, financial and security risks, and the exact tested code state. Verdict: `ACCEPTABLE FOR COMMIT`, `FIXES REQUIRED` or `INSUFFICIENT EVIDENCE`. A recommendation is **not** Mahmoud's acceptance.
- **Gate D — Commit/Push:** user explicitly authorizes. Codex records commit SHA and pushes to `mohameelsherbini/Assab` only. Verify the remote branch and compare it to its intended base. If the code changed after testing, rerun affected checks.
- **Gate E — Upstream PR:** user explicitly authorizes. Compare **base** `mahmoudmagdy200000-code/Assab:sprint/01-financial-foundation` (or the agreed target) against **head** `mohameelsherbini/Assab:task/...`. Check unrelated commits, conflicts, CI, reviewers and compatibility. Do not auto-merge.
- **Gate F — Acceptance:** only explicit Mahmoud approval at a named SHA updates formal task acceptance. Update documentation in a separate approved change.

Never run `git push upstream` or alter branches of Mahmoud's repository. Do not commit secrets, access tokens, DB credentials, generated dependencies, or ignored test artifacts.

## 5. Required task brief (Work → Codex)

Every implementation request should carry:

- Task ID and precise objective; approved scope and excluded work.
- Personal fork, exact base branch and starting full SHA; requested task branch.
- Authoritative plan/decision references, dependencies and cross-repo contracts.
- Business rules, financial invariants, tenant/branch permissions, API compatibility, idempotency and failure/retry rules.
- Expected migrations or data impact, safety guards, rollback considerations.
- Acceptance criteria as verifiable cases, including negative and concurrency tests.
- Targeted test commands, full-suite expectations, runtime/database assumptions, and limits of available environments.
- Explicit instruction to **stop without commit, push, PR or merge**, unless a separate approval authorizes the relevant action.

If any instruction contradicts the repository, approved decisions, or current SHA, stop and ask rather than guess.

## 6. Required implementation and review report

Codex must provide:

1. Task ID, repository, environment, active branch, starting HEAD, ending working state, and `git status`.
2. Files changed, summary of diff, out-of-scope changes (if any), and migrations.
3. Exact commands **actually executed**, exit codes, tests/assertions/failures/errors/skips and relevant logs.
4. Differences between targeted, full-suite, SQLite and MySQL/MariaDB verification; explicitly distinguish `NOT RUN`, `BLOCKED`, `PASS` and `FAIL`.
5. Regression, security, financial, idempotency, tenant isolation, permissions and app/Dashboard compatibility findings.
6. Remaining gaps, blockers and decisions needed; `commit: none`, `push: none`, `PR: none` until explicitly authorized.

Work must independently classify findings by severity, identify the exact evidence inspected, and return a gate verdict. If independent review cannot inspect the same patch or verified tested SHA, use `INSUFFICIENT EVIDENCE`.

## 7. Financial and deployment safety gates

- Preserve atomic financial effects, exactly-once/idempotency protections, ledger/receipt ownership, tenant isolation, authorizations, precision and approved monetary semantics.
- Do not claim SQLite proves MySQL/MariaDB row locks, transactions, deadlocks or concurrency correctness. Verify on **approved disposable test schemas only**, after checking the effective DB connection; never refresh production or preserved baseline databases.
- Respect the existing deployment/readiness documents, including AssabAPP compatibility (D4), unresolved MySQL/MariaDB validation, S1-11 liability/correction requirements and the drain-before-deploy gate for pending handovers.
- A task implementation being closed for sequencing is not production-release acceptance.
- Never alter the release gates or mark a task `Accepted` without recorded approval and supporting evidence.

## 8. Sync and next-task policy

At the start of each task: fetch/recheck the fork and upstream refs, verify full SHA and divergence, and read recent decisions. If upstream has changed, review effects before updating task branches; do not silently rebase or overwrite work.

After an upstream PR is merged: record the PR, base/head/merge SHAs and acceptance status; synchronize the fork carefully; then start the next task branch from the verified agreed base. Keep workflow documents and task evidence updated only through reviewed changes.

## 9. Reusable prompts

### Work — analysis only

> Inspect `mohameelsherbini/Assab` and the exact Sprint/task SHA. Read the authoritative task documents, code and applicable approved decisions. Return a scoped plan, risks, acceptance criteria and test matrix. Distinguish known facts from missing context. Read-only; no modifications, commits, pushes or PRs. Wait for approval.

### Codex — approved implementation only

> Implement the explicitly approved task brief on a dedicated branch of `mohameelsherbini/Assab`. Verify base SHA and working tree first. Preserve financial/security invariants. Add regression coverage; execute safe tests where possible; report the exact diff, executed test results and blockers. Do not commit, push, merge or create a PR. Stop for review.

### Work — independent review only

> Review the actual Codex patch and test evidence against the approved task brief and exact tested SHA. Classify findings as Critical/High/Medium/Low. Check ledger, idempotency, tenant/branch scope, contracts, migrations and regressions. Return `ACCEPTABLE FOR COMMIT`, `FIXES REQUIRED`, or `INSUFFICIENT EVIDENCE`. Do not modify or publish anything.

### Codex — authorized publication only

> After my explicit authorization, verify the approved diff, tests, branch, repository and working tree. Commit exactly the approved scope and push only to `mohameelsherbini/Assab`. Report final SHA and remote comparison. Do not create an upstream PR without separate approval.

## 10. Initial adoption note

At the time this guide was drafted, the last independently verified Sprint branch HEAD was `193ea3c7cc2f88b6bbf0723941335b4eb5accf1d`. This is **historical context, not a permanent pin**: always recheck live refs before any new task. S1-08 was recorded as accepted; S1-09 was development-closed with release gates open; S1-10 Phase 2/D6 required further verification and Mahmoud review; S1-11 remained planned. Refer to the current register for updated status.

---

**Change control:** Changes to this workflow require a reviewed task/PR on the personal fork. No workflow policy overrides project-specific approved technical decisions, security safeguards or Mahmoud's formal acceptance role.

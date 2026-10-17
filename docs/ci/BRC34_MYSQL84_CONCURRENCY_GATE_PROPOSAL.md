# G5 — MySQL 8.4 / concurrency gate: read-only feasibility assessment and implementation proposal

Status of this document: **PROPOSED DESIGN. NOT EXECUTED. NOT PUBLISHED AS A WORKFLOW.**
Updated 2026-10-17 after the BRC v3.4 correction task: §7 lists the concurrency-sensitive code added by the
corrections and the MySQL-gated tests that SHOULD be written (none exist, none were run); §8 names the 33
MariaDB-asserting cases. The gate remains **NOT RUN** — it needs a separate authorization.
No MySQL, MariaDB or concurrency test was run in this task. Everything below comes from
`SOURCE_INSPECTION_ONLY` of the candidate tree (`bc0b87c3…`, task-branch content identical for
application code). Running this gate needs a **separate authorization** from Mahmoud.

Evidence labels used: `SOURCE_INSPECTION_ONLY`, `UNVERIFIED` (hypothesis that only a real run can settle).

---

## 1. Inventory of tests that need a real MySQL-family server

SQLite skips these in both gates (FAST 66, FULL 73 + 1 driver-limitation skip). Counts are skipped
test cases in the reviewer-executed FULL run.

| # | Class | Skipped | Opt-in switch (exact) | Allowed schema name (regex in the test) | Host rule | Asserts the server IS MariaDB? | Covers |
|---|---|---:|---|---|---|---|---|
| 1 | `Pkg67MariaDbCompositionTest` | 22 | `PKG67_MARIADB_TESTS=1` **and** driver `mysql` | `^pkg67_disposable_[a-z0-9_]+$` | `localhost` / `127.0.0.1` | **Yes** (`assertStringContainsString('MariaDB', VERSION())`) | not-worked vs correction / recount / daily submit races (A1–A4, B1–B3, C1–C7), idempotent replay of submit, HTTP idempotency isolation + rollback, two identical marks → one audit fact |
| 2 | `Pkg67MigrationB01Test` | 5 | `PKG67_MARIADB_TESTS=1` | same as #1 | same | No | PKG-2 delegation-audit migration: fresh install, complete schema, partial schema, missing columns, rollback guard |
| 3 | `Pkg6ReviewMySqlConcurrencyTest` | 4 | `PKG6_MARIADB_TESTS=1` | `^pkg6_disposable_[a-z0-9_]+$` | same | **Yes** | correction vs approval, return vs final approval, two manager resubmissions, duplicate corrections + rollback |
| 4 | `ShiftNotWorkedMySqlConcurrencyTest` | 3 | `PKG7_MYSQL_TESTS=1` | `^pkg7_test_[a-z0-9_]+$` | same | No (messages only) | two marks → one audit fact; mark vs start; mark vs reassignment |
| 5 | `ShiftPhases234MySqlConcurrencyTest` | 10 | **none** — only driver `mysql` + schema name | `^s111_phase(?:567_phase)?234_audit_[a-z0-9_]+$` | `127.0.0.1` / `localhost` | No | over-reservation of manager cash by two workdays; replacement vs confirmation (both orders); submitted boundary vs report mutation; stale revision/count; MySQL actor-column fit; lost HTTP response replay after real commit |
| 6 | `ShiftPhases567MySqlConcurrencyTest` | 12 | `S111_PHASE567_MYSQL_TESTS=1` | `^s111_phase567_test_[a-z0-9_]+$` | same | No | accountant corrections with same expected version; liability allocations; correction vs manager approval; redistribution vs posting; forced-deadlock retry without duplicate balance/history/outbox; public accountant command retry + replay; correction vs daily-submitted boundary; canonical lock order; AUD-05 first discovery |
| 7 | `ShiftPhases8910MySqlConcurrencyTest` | 10 | `S111_PHASE8910_MYSQL_TESTS=1` | `^s111_phase(567_phase)?8910_test_[a-z0-9_]+$` | same | No | settlements with same expected version; same-key daily submits; same key + different payload; correction vs daily submit; F-12 manager-cash mutex across receipts / returns / settlement / reservation |
| 8 | `AdminLegacyRefreshConcurrencyTest` (FULL only) | 4 | none; driver `mysql` or `mariadb` | name starts with `s12_at02_` | (none in test) | **Yes** | refresh vs revocation / password change / token-only logout |
| 9 | `AdminSessionRevocationTest` — only the 3 `legacy_logout_concurrency` cases (FULL only) | 3 | selected by test name; driver `mysql`/`mariadb` | name starts with `s12_fs12_` | (none in test) | **Yes** | legacy logout vs refresh ordering |

Totals: 73 skipped test cases (66 reachable from the FAST list + 7 Admin/session in FULL).
Mapping to the categories requested in the task:

| Category | Where it is exercised (class → examples) |
|---|---|
| row locking / independent transactions | every class above spawns real PHP worker processes with their own PDO connection and file barriers (`Symfony\Process`) |
| concurrent request | #7 daily submits; #5/#6 HTTP-level calls; #1 `c1…c5` |
| idempotency race | #7 same key / different payload; #1 `c7`, `http_idempotency_isolation_and_rollback`; #5 lost HTTP response replay |
| cash-transfer confirmation | #5 over-reservation, replacement vs confirmation, confirmed receipt vs replacement; #7 F-12 tests |
| correction race | #3, #6 (accountant/manager), #1 `A2/B1/B2` |
| double posting | #6 forced-deadlock retry, redistribution vs posting; #3 duplicate corrections |
| shift close | #1 `C1–C7`, #5 submitted boundary, #7 daily submit |
| liability posting | #6 allocations / posting-actual race / AUD-05 |

What these would upgrade in the AC matrix if they pass (they are **not** counted anywhere today):
AC-12 (reservation race), AC-17 (replacement vs confirmation), AC-19/21/22 (correction races),
AC-26 (not-worked races), AC-34/35 (daily submit), AC-31 only if an expense race test is added
(none exists).

## 2. Isolation facts the gate must respect (SOURCE_INSPECTION_ONLY)

* The tests do **not** create databases. Classes #1, #2 and #3 call `migrate:fresh --force` themselves
  on the connected schema (destructive; #2 also re-runs `migrate` and deletes rows of the audit table);
  #4–#9 contain no migration call and expect an already-migrated schema (#5 states it "never
  migrates or truncates"). Each class refuses to run unless the schema name matches its regex and the
  host is loopback — that is the application-level last line of defense, not the first.
* `phpunit.xml` forces SQLite; the committed `.env.testing` points to MySQL `assab_test`
  (`127.0.0.1:3306`, `root`, a committed password). A MySQL gate therefore **must not** rely on
  `.env.testing`; it needs its own generated PHPUnit configuration (forced environment) and a
  process environment that already contains every `DB_*` value (Dotenv never overrides existing
  variables).
* Worker processes inherit credentials through `DB_*` values handed over by the test; default port
  fallback in #6/#7 is `3310` when the connection has no port.
* Application code uses the Laravel `mysql` driver (also for MariaDB in the historical CI); one
  Shift migration branches on `mysql || mariadb`, several Custody/Expense migrations on `mysql` only.
  The gate must therefore connect with `DB_CONNECTION=mysql`.
* No application source (Services) contains MariaDB-only SQL (`SKIP LOCKED`, `GET_LOCK`,
  `SET SESSION innodb_*`): none found by grep. The remaining engine risk sits in behavior, not syntax.

## 3. MariaDB-specific assumptions versus MySQL 8.4

| Item | Finding | Consequence for a MySQL 8.4 run |
|---|---|---|
| Hard `MariaDB` assertion in `VERSION()` | classes #1, #3, #8, #9 (33 test cases) | On MySQL 8.4 these **fail** at the assertion (they do not skip). They need either a MariaDB service as authored, or an engine-aware assertion (a **test change = separate approval**). |
| Classes without an engine assertion | #2, #4, #5, #6, #7 (40 test cases) | Runnable as authored on MySQL 8.4 — behavior still `UNVERIFIED`. |
| Historical CI (`assab-backend-ci.yml`, `mariadb-concurrency` job, trigger only on branch `test/s1-12`) | used `mariadb:11.4`, root password CI-only, `migrate:fresh` into per-suite databases, covers only #8/#9 | Evidence that a service-container pattern already exists; it is not a MySQL 8.4 gate and covers 7 of 73 skipped cases. |
| Staging / production engine | `UNKNOWN` — the project's own scope document lists "MySQL version, hosting" as an open question (C-07); the E2E checklist leaves "DB engine / version" blank | Decide whether MariaDB, MySQL 8.4 or both must be proven before treating a green run as relevant to the deployed engine. |
| Snapshot semantics (REPEATABLE READ read-view, gap locks) | tests such as `fence_holds_when_transaction_snapshot_predates_a_committed_mark`, `stale_snapshot_submit_excludes…`, `daily_close_scope_excludes_a_mark_committed_after_the_transaction_snapshot` depend on InnoDB read-view behavior | `UNVERIFIED`: expected to match, only a run settles it |
| `ONLY_FULL_GROUP_BY` / strict mode / JSON column semantics / `explicit_defaults_for_timestamp` defaults | MySQL 8.4 enables stricter defaults than MariaDB; the project config sets `strict => true` | `UNVERIFIED`: migrations (309 files) and queries may surface differences; B-01 identifier-length test exists for MariaDB limits (64 chars is common to both) |
| Authentication | MySQL 8.4 defaults to `caching_sha2_password` (`mysql_native_password` disabled by default) | PHP 8.3 `pdo_mysql`/mysqlnd supports it; `UNVERIFIED` on the exact runner image |

## 4. Proposed disposable MySQL 8.4 service design (PROPOSED DESIGN)

Separate workflow `assab-brc34-mysql-concurrency.yml` — **never** added to the FAST/FULL workflow,
**never** triggered automatically.

1. **Trigger**: GitHub offers `workflow_dispatch` only for workflow files that exist on the
   **default branch** (the same limit was observed with the SQLite workflow, which therefore runs on
   `push`/`pull_request` of its task branch). Until a workflow file is merged to `main`, the gate must
   be started by a `push` to a dedicated branch `task/brc34-mysql-gate-*` whose head commit message
   contains the literal token `[mysql-gate:authorized]` (job `if:` on that token). After a merge,
   switch to `workflow_dispatch` with a required text input
   `confirm = I_AUTHORIZE_DISPOSABLE_MYSQL_RUN` and an `engine` choice (`mysql-8.4`, `mariadb-11.4`).
   Optional GitHub Environment with required reviewer (repository setting — not configurable from
   this task).
2. **Permissions / secrets**: `permissions: contents: read`; no `secrets.*`; no deployment tokens;
   no `environment:` that carries secrets; `persist-credentials: false`.
3. **Service container**: `mysql:8.4.<pinned patch>` (resolve and record the image digest; the
   historical job used an unpinned `11.4` tag — do better), root password CI-only literal bound to
   the job (service exists only for the job lifetime), published as `127.0.0.1:3310→3306`,
   health-check `mysqladmin ping`, tmpfs data dir.
4. **Layer-1 shell guard** (same philosophy as the SQLite gate): `DB_HOST=127.0.0.1`, `DB_PORT=3310`,
   reject `DB_URL`/`DATABASE_URL`, reject any variable whose name or value mentions `coolify`,
   `staging`, `production`, `railway`, `hostinger`; require `runner=github-hosted`.
5. **Layer-2 server fingerprint** before any test: `SELECT VERSION(), @@version_comment` must match
   the selected engine (`8.4.*` + "MySQL Community Server"; not MariaDB), and
   `information_schema.SCHEMATA` may contain only system schemas — anything named `assab*`
   aborts the job.
6. **Schema provisioning** (one schema per class, names exactly matching the test regexes, with a
   per-run suffix): `pkg67_disposable_<run>`, `pkg6_disposable_<run>`, `pkg7_test_<run>`,
   `s111_phase234_audit_<run>`, `s111_phase567_test_<run>`, `s111_phase8910_test_<run>`,
   `s12_at02_<run>`, `s12_fs12_<run>`. Schema creation and `migrate:fresh` happen **in the
   workflow shell**, before PHPUnit, only for classes that expect a migrated schema (#5–#9);
   #1/#3 migrate themselves. Names are validated against the same regexes in the shell.
7. **Layer-3 PHPUnit bootstrap (MySQL variant)**: generated config forcing `DB_CONNECTION=mysql`,
   loopback host, name regex allow-list, and the opt-in switch of the specific class; refuses any
   other schema even if a test would accept it.
8. **Execution**: strictly serial (no ParaTest — shared server, barriers, deadlock tests), one
   PHPUnit invocation per class, `--log-junit`, 90-minute job timeout, `fail-fast: false`.
9. **Fixtures**: synthetic only — each test builds its own companies/branches/users
   (`MySQL Test Company <uniqid>`, …); nothing is imported; schemas are dropped at the end
   (`always()`).
10. **Evidence**: per-class JUnit, console log with skip reasons, server fingerprint, migration
    log tail, `::notice::` annotations, SHA-256 manifest; summarizer reused (new category
    `MYSQL_GATE_EXECUTED`; any skip is a **failure** of the gate, not a pass).
11. **Failure handling**: classify as ENV / ENGINE-DIFFERENCE / APPLICATION; application defects are
    written as findings with a scoped correction proposal — no application edit in the CI task.

### Illustrative skeleton (not committed, not validated)

```yaml
name: ASSAB BRC34 MySQL concurrency gate (manual, disposable)
on:
  push:
    branches: ['task/brc34-mysql-gate-*']      # until the file exists on the default branch
  workflow_dispatch:                             # usable only after it exists on the default branch
    inputs:
      confirm:    { description: 'type I_AUTHORIZE_DISPOSABLE_MYSQL_RUN', required: true }
      engine:     { type: choice, options: [mysql-8.4, mariadb-11.4], default: mysql-8.4 }
permissions: { contents: read }
jobs:
  mysql-gate:
    if: ${{ inputs.confirm == 'I_AUTHORIZE_DISPOSABLE_MYSQL_RUN' || contains(github.event.head_commit.message, '[mysql-gate:authorized]') }}
    runs-on: ubuntu-24.04
    timeout-minutes: 90
    services:
      db:
        image: mysql:8.4          # pin patch + digest before use
        env: { MYSQL_ROOT_PASSWORD: ci-only-<literal> }
        ports: ['127.0.0.1:3310:3306']
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1 -p$MYSQL_ROOT_PASSWORD"
          --health-interval=5s --health-retries=20
    steps:
      # checkout exact SHA, reuse .github/actions/brc34-sqlite-setup (source guard), then:
      # 1 guard env  2 fingerprint server  3 create schema(s)  4 migrate (#5-#9)  5 per-class phpunit (serial)
      # 6 summarise (skips = failure)  7 sha256 manifest  8 upload  9 drop schemas (always)
```

## 5. Decisions needed from Mahmoud before G5 can run

1. **Which engine(s)** must the gate prove: MySQL 8.4 only, MariaDB 11.4 only, or both. (Staging engine is `UNKNOWN`.)
2. **MariaDB-asserting classes (33 cases)**: run them on MariaDB as authored, or approve a
   separate test-only change to make the assertion engine-aware. Recommendation: first run the 40
   assertion-free cases on MySQL 8.4 and the 33 on MariaDB 11.4 (no test edits); revisit afterwards.
3. Authorization to **execute** the gate (pushing and dispatching the new workflow).
4. Whether a **GitHub Environment** with required reviewer will be configured by the repository owner.
5. Whether to add missing concurrency tests first (expense double-decision race for EXP-07, CASH-08 undecided
   inbound) — they do not exist today, so a green MySQL run would still leave AC-31 `PARTIAL`.

## 6. Not done here (explicit)

* No MySQL/MariaDB container was started; no concurrency test was run; no migration was executed
  on any server; no connection to Staging or any external database was made.
* The skeleton above has never been executed and may need adjustment.


## 7. Concurrency-sensitive code added by the 2026-10-17 corrections (PROPOSED tests — NOT written, NOT run)

SQLite is a single writer and ignores `SELECT … FOR UPDATE`, so the row locks below are *implemented* but only a
two-connection MySQL run can prove them. Until then these AC parts stay `NOT_RUN`:

| # | Code (commit) | Proposed MySQL-gated test | AC part it would settle |
|---|---|---|---|
| M-1 | `ExpenseCustodyService::reserve` under the manager row lock (`235d1c72`) | two submissions of two expenses against a custody that covers only one → exactly one 201 and one 400 `Insufficient custody balance`; custody never negative | AC-29 / EXP-05 under concurrency |
| M-2 | `ExpenseApprovalService` approve/reject with `lockForUpdate` + status re-check (`235d1c72`) | approve ‖ reject (and reject ‖ reject) of the same expense → one effect, one reversal, one event | AC-31 / EXP-07 simultaneous decisions |
| M-3 | `resubmit` status check (`235d1c72`) | double-click resubmit → one charge | AC-30 / EXP-08 |
| M-4 | `SalesCashTransferService::transfer` under `lockManagerCashOwner` (`69658710`) | two transfers whose sum exceeds the transferable ceiling → exactly one posts; same idempotency key twice concurrently → one posting, one replay | AC-54/56 / FUND-02 under concurrency |
| M-5 | `CustodyRequestService::resubmitRequest` `lockForUpdate` (`9f781a40`) | two resubmits of one rejected request → one Pending transition, one timeline row | AC-57 / FUND-05 |
| M-6 | `lockCashierOwners` with `withTrashed()` (`d5da3057`) | `deleteCashier` ‖ manager confirmation of his pending handover → the confirmation always finds the owner row; no stranded handover | AC-63/64/69 under concurrency |

All six would reuse the existing worker/barrier pattern of `ShiftPhases8910MySqlConcurrencyTest` and must follow the same
isolation rules (disposable schema name regex, loopback host, opt-in switch).

## 8. The 33 MariaDB-asserting cases (fail — not skip — on MySQL 8.4 unless made engine-aware or run on MariaDB 11.4)

| Class | Cases | Names |
|---|---:|---|
| `Pkg67MariaDbCompositionTest` (`PKG67_MARIADB_TESTS=1`) | 22 | `control_the_day_fixture_submits_through_the_real_route`, `a1_stale_transaction_cannot_manager_correct_a_marked_not_worked_shift`, `a2_no_work_vs_manager_correction_of_the_returned_report_never_deadlocks_and_keeps_history`, `a3_marking_a_shift_that_has_a_report_is_rejected_while_the_manager_corrects_it`, `a4_a_sibling_branch_manager_cannot_correct_through_the_early_return_path`, `b1_no_work_vs_recount_rejects_marking_a_counted_shift_and_preserves_history`, `b2_no_work_vs_recount_on_an_untouched_shift_has_one_winner_and_no_evidence`, `b3_stale_transaction_cannot_recount_a_marked_not_worked_shift`, `c1_mark_commits_first_then_the_real_submit_excludes_the_marked_shift`, `c2_submit_holds_its_transaction_and_the_mark_waits_then_is_rejected_on_the_submitted_day`, `c3_mark_holds_its_transaction_and_the_real_submit_waits_then_excludes_it`, `c4_free_race_between_mark_and_submit_has_only_two_consistent_outcomes`, `c5_stale_snapshot_submit_excludes_a_mark_committed_after_the_snapshot`, `c6_a_corrupt_no_work_fact_on_a_reported_shift_fails_the_submit_closed`, `c7_idempotent_replay_of_the_submit_creates_no_second_effect`, `mark_vs_accountant_correction_on_the_same_cashier_never_deadlocks`, `mark_vs_head_return_on_the_same_cashier_never_deadlocks`, `mark_vs_correction_on_the_same_untouched_shift_has_one_winner_and_no_report_evidence`, `two_identical_marks_commit_exactly_one_audit_fact_on_mariadb`, `fence_holds_when_transaction_snapshot_predates_a_committed_mark`, `daily_close_scope_excludes_a_mark_committed_after_the_transaction_snapshot`, `http_idempotency_isolation_and_rollback_on_mariadb` |
| `Pkg6ReviewMySqlConcurrencyTest` (`PKG6_MARIADB_TESTS=1`) | 4 | `correction_versus_approval_reads_new_count_after_waiting`, `return_wins_against_final_approval_without_reopening_cash`, `two_manager_resubmissions_have_one_winner`, `duplicate_corrections_and_rollback_have_no_duplicate_effect` |
| `AdminLegacyRefreshConcurrencyTest` | 4 | `two_refreshes_with_same_resolved_candidate_create_one_successor_pair`, `revocation_and_password_change_winning_prevent_stale_refresh_revival`, `refresh_winning_serializes_with_revocation_and_password_change`, `token_only_logout_winning_prevents_stale_refresh_revival` |
| `AdminSessionRevocationTest` (only the `legacy_logout_concurrency` cases) | 3 | `legacy_logout_concurrency_refresh_first_removes_committed_successors`, `legacy_logout_concurrency_logout_first_prevents_waiting_refresh_successors`, `legacy_logout_concurrency_d08_first_prevents_waiting_refresh_successors` |

Recommendation unchanged: run these 33 on MariaDB 11.4 as authored and the other 40 on MySQL 8.4; change the assertion to be
engine-aware only after a separate test-change approval. **Status: NOT_RUN.**

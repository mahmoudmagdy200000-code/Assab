# S1-01 baseline

## Current disposition — focused MySQL baseline passed, 2026-10-07

**Ready for review; not Accepted.** The isolated 3310 infrastructure blocker and stale BranchFactory fixture blocker are resolved. The final migrated `branches` schema lacks `map_coordinates`, so the sole S1-01 code change removed that field from `Modules/Branch/database/factories/BranchFactory.php`. PHP lint passed. All six focused files ran individually against disposable `assab_s1_test` using process-local credentials: **41 tests, 144 assertions, 41 PASS, 0 assertion failures, 0 errors, 0 blocked**. Both the original `assab_s1_local` and test schema still have 283 migration rows and zero persistent business rows among 211 non-migration base tables. The normal ignored `.env` still targets `assab_s1_local`; S1-01 changed no migration, schema, model, business logic or seed. The separately published S1-02/S1-03 documentation work is recorded below and in task-register.md; it is not accepted implementation.

The remaining S1-01 step is Mahmoud's review of the baseline package. Passing these existing tests does not constitute acceptance of the later Sprint 01 business rules; source-identified contract and monetary-unit gaps remain for their planned tasks. A Purchase test-data seeder still writes legacy `map_coordinates` but was not run and is outside this fixture correction. See [verification.md](verification.md) for the six-file result matrix and logs. Earlier outcomes below are historical.

## INITIAL DISCOVERY SNAPSHOT

All three repositories were successfully cloned during initial preparation. No historical reset was performed. The table below is an initial-discovery snapshot, not the current branch tips; subsequent local and published commits are preserved in Git history.

| Role / absolute path | Branch | HEAD | Origin fetch and push | Before / after this run |
|---|---|---|---|---|
| Backend `D:\claude\AssabERP\Assab` | sprint/01-financial-foundation | 7b895e61832ecc3fd59beb6b843dcaacfc0d7d9d | https://github.com/mahmoudmagdy200000-code/Assab.git | One-line BranchFactory deletion plus eight untracked docs/sprint-01 files; nothing staged |
| Dashboard `D:\claude\AssabERP\dashboard` | sprint/01-financial-foundation | 0378530867c8a14706213768ce49553cefc203b2 | https://github.com/mahmoudmagdy200000-code/dashboard.git | Clean / clean |
| Read-only reference `D:\claude\AssabERP\AssabAPP` | main | b2453481966fc1ae2cdfcac4161bbb29a3ba5828 | https://github.com/mahmoudmagdy200000-code/AssabAPP.git | Clean / clean |

Git ownership checks required a command-scoped override during initial discovery, e.g. `git -c safe.directory=D:/claude/AssabERP/Assab -C D:/claude/AssabERP/Assab status --short --branch`. No global Git configuration was changed. Existing root `BASELINE-DISCOVERY.md` is the earlier discovery report; the eight S1-01 files in this directory form the S1-01 review package.

The earlier published baseline commit `5b0066ffdd44d2c3aad9dcfabff9a2f3181dee59` recorded a separate SQLite run of 41 tests and 144 assertions at the initial codebase. That is historical, developer-reported context only. The current S1-01 test evidence is the isolated MySQL 3310 run in [verification.md](verification.md); neither run establishes the future 115/50/25/10/30 acceptance scenario.

## Authority and instructions

The four files in `project-docs` were inventoried. Execution protocol, business rules, and sprint plan were read; schema overview/navigation and relevant table/migration sections were retrieved selectively. The 2.28 MB schema appendix was not repeatedly loaded. Source-projected schema is checked against repository migrations/models, not a live database. Initial discovery compared all 283 appendix migration hashes with checked-out migrations using LF-normalized contents: 283 matches, no missing/different files. This is source consistency evidence only.

| Workspace source | SHA-256 |
|---|---|
| ASSAB_AGENT_EXECUTION_AND_REVIEW_PROTOCOL.md | A96607441BA525F277880225746DE3B61E5CC412B8274CDDC0B26398C8717051 |
| ASSAB_DATABASE_SCHEMA.md | 9DB0105B32C601F96E9EAE5AE44BFB19E8776EEF190B8C7221F3B2733EB4A6D4 |
| Assab-ERP-Cash-Cycle-Business-Rules-v2.0-EN.md | 7BB57AD79B520B1C46C6793AC6396C43024D6F25B30508049A1FAAE25C88EEB6 |
| Assab-ERP-Sprint-01-Agent-Implementation-Plan.md | 37583220BE76F3AB688BFDA3172070E07A65ED0B44507AEF97592344401F37E8 |

The supplied document versions and exact filenames are:

| Supplied filename | Explicit version in source | SHA-256 |
|---|---|---|
| `ASSAB_AGENT_EXECUTION_AND_REVIEW_PROTOCOL.md` | 1.0 | A96607441BA525F277880225746DE3B61E5CC412B8274CDDC0B26398C8717051 |
| `ASSAB_DATABASE_SCHEMA.md` | 2.0 — Final source-reviewed reference, dated 6 October 2026 | 9DB0105B32C601F96E9EAE5AE44BFB19E8776EEF190B8C7221F3B2733EB4A6D4 |
| `Assab-ERP-Cash-Cycle-Business-Rules-v2.0-EN.md` | Business-rule version 2.0 | 7BB57AD79B520B1C46C6793AC6396C43024D6F25B30508049A1FAAE25C88EEB6 |
| `Assab-ERP-Sprint-01-Agent-Implementation-Plan.md` | 2.0 — English, consolidated developer handoff | 37583220BE76F3AB688BFDA3172070E07A65ED0B44507AEF97592344401F37E8 |

## CURRENT S1-01 REVIEW PROVENANCE

Repository refs captured for this documentation correction on 2026-10-07. The initial-discovery values above remain historical and are not overwritten.

| Repository role | Branch | HEAD |
|---|---|---|
| Backend `D:\claude\AssabERP\Assab` | `sprint/01-financial-foundation` | `33ecd35879f125d5de5c9fa7b1da0c7bc56a8adb` |
| Dashboard `D:\claude\AssabERP\dashboard` | `sprint/01-financial-foundation` | `0378530867c8a14706213768ce49553cefc203b2` |
| Mobile compatibility reference `D:\claude\AssabERP\AssabAPP` | `main` | `b2453481966fc1ae2cdfcac4161bbb29a3ba5828` |

Mohamed's approved BR-01–BR-25 define requirements. Current code/tests establish current behavior. Mahmoud owns technical review and acceptance. Commit and publication actions require the applicable user authorization. Machine changes and acquisition of missing tools are outside this baseline. Historical documents are navigation aids, never reset instructions.

Applicable `Assab/CLAUDE.md`: Controllers → FormRequests → Services → Repositories → Models, existing module layout and API envelopes; authorization and tenant scoping; atomic multi-table writes with locks; additive reversible migrations without editing shipped migrations; shared Support classes and dependency injection. Do not introduce app/Domain/Actions or strict_types wholesale. PHPStan is not installed; Pint/Pest are the relevant project gates. High-impact financial/security implementation requires the concrete blueprint at S1-05 before implementation.

No AGENTS.md was found in the workspace/ancestor instruction search or cloned repository file inventories. Nested `Assab/work-flow/CLAUDE.md` and `work-flow/claude-workflow-laravel/CLAUDE.md` are generic templates scoped to those directories; they do not replace root project conventions. The local laravel-feature skill's project overrides and laravel-code-review checklist were read and applied to discovery/self-review. Generic checklist assumptions about Actions, strict_types, or PHPStan are superseded by root instructions. Constitution `.specify/memory/constitution.md` v1.2 describes layering, policies, testing/performance goals, but its Laravel 11/app/Modules references differ from the actual Laravel 12/Modules checkout. App README references an absent constitution: recorded, not recreated in the read-only repository. No dashboard-specific CLAUDE.md/AGENTS.md was found.

## Current runtime and isolation

Portable PHP 8.4.26, Composer 2.10.3 and pnpm 10.34.6 are installed under the workspace `.tools/s1-01` directory. Locked backend/dashboard dependencies and PHP platform requirements passed earlier checks. Node v22.22.2 and Corepack 0.34.6 are the recorded host runtime. No dependency or lockfile change is part of this delivery.

MySQL 8.4.11 at `127.0.0.1:3310` uses `.tools/s1-01/mysql-local/data`. `assab_s1_local` is the original migrated baseline, and the ignored Backend `.env` selects its DML-only runtime account. `assab_s1_test` is a separate disposable schema; its account has schema-scoped DDL/DML rights required by the existing `RefreshDatabase` tests and cannot read the baseline or `mysql.user`. Credentials remain outside the repository in protected local files; tests load their password process-locally. No credential value is part of the proposed commits. The older 3307/3308 instances were not accessed during focused testing or this delivery review.

## CURRENT TEST SAFETY GUARD

Before running any focused PHPUnit test that uses `RefreshDatabase`, follow the fail-fast procedure in [verification.md](verification.md#current-test-safety-guard). It resolves Laravel's effective default connection, rejects `assab_s1_local`, non-loopback hosts, alternate ports, URL overrides, and every database name except `assab_s1_test`, then makes a read-only connection check. `RefreshDatabase` / `migrate:fresh` is authorized only against `assab_s1_test`. `assab_s1_local` is the migrated baseline and must never be refreshed. Keep DB credentials process-local; the guard does not display them. For complete Unit → Feature → NFR serial runs with visible PHPUnit progress and JUnit files, use [test-runner-workflow.md](test-runner-workflow.md) and `scripts/run-serial-tests.ps1`.

The six test files ran in separate processes against the disposable schema. Each process refreshed only that schema, and teardown rolled back fixtures. Both schemas retained 283 migration records and zero nonempty business tables after testing. The recorded baseline inventory is 212 base tables, two views, two procedures and 179 foreign keys; only the migration repository contains rows. See verification.md for evidence limits and individual test results.

## Review and remaining boundaries

S1-01 is Ready for review, not Accepted. The test results establish existing behavior; they do not establish full BR-01–BR-25, A01–A18 or AC-01–AC-21 acceptance. API-contract UNKNOWN/REQUIRES VERIFICATION entries and source-identified financial gaps remain for Mahmoud's review and later authorized tasks. A Dashboard TypeScript no-emit check was previously recorded PASS, but its exact command and tested SHA were not retained; a current attempt stopped before TypeScript with pnpm `EPERM` while resolving the Dashboard directory. A Dashboard build/dev-server check and complete API-to-UI acceptance are not claimed.

The only S1-01 application-code change is removal of the stale BranchFactory field. The Purchase test-data seeder's remaining reference is a separate backlog finding in task-register.md. Deployment requires no new migration, configuration, queue restart or cache clear for this fixture/docs change. The published S1-02/S1-03 documents are review artifacts only; they do not establish acceptance or authorize implementation. Historical setup/recovery proposals are archived in their own documents and must not be executed as current instructions.

## Reproducible current startup and focused-test handoff (G07)

This is the **current recorded procedure**, not a command execution during the S1-01–S1-05 documentation correction. Backend root is `D:\claude\AssabERP\Assab`; Dashboard root is `D:\claude\AssabERP\dashboard`. Use portable PHP 8.4.26 at `D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe` and Composer 2.10.3 at `D:\claude\AssabERP\.tools\s1-01\composer-2.10.3\composer.phar`; from Backend root, `& 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe' 'D:\claude\AssabERP\.tools\s1-01\composer-2.10.3\composer.phar' --version` verifies the local Composer runtime. Dependencies were already installed against the checked-in lockfile; if installation is later needed, use `composer install` with that lockfile and project-approved environment, never `composer update` as startup. The local MySQL 8.4.11 endpoint is `127.0.0.1:3310`. `assab_s1_local` is the migrated **baseline** and must never receive `RefreshDatabase`, `migrate:fresh`, broad seed, or destructive setup. `assab_s1_test` is the separate disposable schema for focused tests; obtain its credentials from the existing protected local credential file and load them process-locally, without printing them or writing them to tracked files.

From the Backend root, route registration can be inspected without invoking handlers: `& 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe' artisan route:list --path=api --json`. Before **each** test file, execute the complete effective-connection fail-fast guard in [verification.md](verification.md#current-test-safety-guard); it verifies the live selected DB is exactly `assab_s1_test` on loopback port 3310 and refuses the baseline. The known focused form is `& 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe' vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result tests/Feature/ShiftCycleFixesTest.php` (default progress enabled). Other recorded focused files are `ShiftCloseChainTest.php`, `ShiftHandoverVarianceCustodyTest.php`, `SalesVarianceAllocationTest.php`, `HandoverLedgerDateTest.php` under `tests/Feature`, and `tests/NFR/Security/AuthenticationTest.php`; run separately with the guard each time. These are historical S1-01 results, not reruns in this correction pass.

Dashboard source package script is `pnpm --filter @workspace/mockup-sandbox dev` from the Dashboard root, using the recorded pnpm 10.34.6/Corepack and Node 22.22.2 toolchain. This is a source-backed command, not a claim that a dev server was started now. Archived environment/recovery documents are historical evidence only; do not revive their older ports, DB targets, reset or install instructions as current procedure.

## Final handoff discovery addendum — 2026-10-07

C-1…C-8 from Mahmoud’s authoritative final handoff are source-inspection findings, not runtime acceptance. Source: `project-docs/Assab-Final-Developer-Handoff-S1-01-S1-05-2026-10-07-1.md`.

| ID | Discovery fact | Contract consequence |
|---|---|---|
| C-1 | AssabAPP calls uncovered reassignment, workday end/daily-close, and rejection-decision writers, plus shift start/end/handover. | Dispositions cover live client paths. |
| C-2 | No client calls `POST …/workday/daily-close/submit`; App button flips a local flag; Dashboard has no caller. | Server guard remains S1-07; client wiring is later work. |
| C-3 | App sends `to_branch_manager` with branch-manager type; backend ignores it and selects first active manager. | Target maps selected ID to `branch_manager_id`, without auto-selection. |
| C-4 | App omits zero-valued channel/own-share fields; multipart amounts are decimal strings. | Omitted legacy channels mean zero; exact string parsing is feasible. |
| C-5 | App displays inclusive VAT, but fractional display parsing truncates and current server VAT formula differs. | Server correction is S1-06; app display correction is later work. |
| C-6 | App parses legacy `variance` as integer and `variance_type` as string. | Preserve legacy meaning/type; proposed semantic decimal-SAR keys are additive. |
| C-7 | No legacy refresh flow/endpoint; 401 logs out; cashier expires after 30 days only with remember-me, otherwise no expiry; manager token has no expiry. | D7 remains proposed/blocked pending Mahmoud; Dashboard Admin auth is separate. |
| C-8 | Dashboard branch open hard-codes 50,000 halalas; branch close sends no count and unsupported `registerClosingHalalas`; accountant close sends count but rounds excess precision; mutations lack stable keys. | Configured opening is not receipt evidence; later Dashboard work owns payload/precision/key corrections. |

S1-15 regression record (defects reproduced by the independent reviewer on 2026-10-07): **RX-01** (TX-02 / FIN-06) — `BridgeLegacyCashierShift` reads `shift_sales_breakdown` before `ShiftEndService` saves it, so the Admin projection gets `aggregatorTotalsHalalas = 0` and a phantom shortage equal to the app sales; on head final approval `ShiftCloseService::onFinalApproved` defaults it to the cashier and it reaches payroll export net pay. **RX-02** (SEC-01) — `ShiftVarianceController::recordVariance` loads the shift by ID with no owner/branch check, so another branch's user can write liability rows; the test asserts 403/404 and zero `shift_variance_details` rows. **RX-03** (AUTH-02) — Admin `/api/v1/auth/*`: Sanctum tokens never expire (`expiresIn` 900 is cosmetic), the refresh token is accepted as a bearer access token, and an inactive user can refresh. *(Descriptions corrected 2026-10-08, audit N-05; RX-03 is the Dashboard/Admin auth contract, not legacy mobile.)* Each regression asserts status, amounts, and relevant row counts; `assertTrue(true)` is not evidence. MySQL locking/concurrency requires MySQL, not SQLite in-memory. Tests were not run in this documentation pass.

D1: Execution Plan v2.0 controls scope, task numbering, and acceptance IDs; Agent Implementation Plan is an execution aid. No separate Execution Plan v2.0 mapping document was found in the inspected workspace/project-docs inventory. Record only mappings explicitly supplied by Mahmoud; do not invent unseen IDs.

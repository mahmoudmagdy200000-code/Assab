# S1-01 baseline

## Current disposition — focused MySQL baseline passed, 2026-10-07

**Ready for review; not Accepted.** The isolated 3310 infrastructure blocker and stale BranchFactory fixture blocker are resolved. The final migrated `branches` schema lacks `map_coordinates`, so the sole code change removed that field from `Modules/Branch/database/factories/BranchFactory.php`. PHP lint passed. All six focused files ran individually against disposable `assab_s1_test` using process-local credentials: **41 tests, 144 assertions, 41 PASS, 0 assertion failures, 0 errors, 0 blocked**. Both the original `assab_s1_local` and test schema still have 283 migration rows and zero persistent business rows among 211 non-migration base tables. The normal ignored `.env` still targets `assab_s1_local`; no migration, schema, model, business-logic, seed, commit, push or S1-02 change occurred.

The remaining S1-01 step is Mahmoud's review of the baseline package. Passing these existing tests does not constitute acceptance of the later Sprint 01 business rules; source-identified contract and monetary-unit gaps remain for their planned tasks. A Purchase test-data seeder still writes legacy `map_coordinates` but was not run and is outside this fixture correction. See [verification.md](verification.md) for the six-file result matrix and logs. Earlier outcomes below are historical.

## Repository snapshot

All three repositories were successfully cloned during initial preparation. No historical reset was performed; current HEADs happen to match the reference revisions. The backend and dashboard developer branches already existed when this run began.

| Role / absolute path | Branch | HEAD | Origin fetch and push | Before / after this run |
|---|---|---|---|---|
| Backend `D:\claude\AssabERP\Assab` | sprint/01-financial-foundation | 7b895e61832ecc3fd59beb6b843dcaacfc0d7d9d | https://github.com/mahmoudmagdy200000-code/Assab.git | One-line BranchFactory deletion plus eight untracked docs/sprint-01 files; nothing staged |
| Dashboard `D:\claude\AssabERP\dashboard` | sprint/01-financial-foundation | 0378530867c8a14706213768ce49553cefc203b2 | https://github.com/mahmoudmagdy200000-code/dashboard.git | Clean / clean |
| Read-only reference `D:\claude\AssabERP\AssabAPP` | main | b2453481966fc1ae2cdfcac4161bbb29a3ba5828 | https://github.com/mahmoudmagdy200000-code/AssabAPP.git | Clean / clean |

Git ownership checks required a command-scoped override during initial discovery, e.g. `git -c safe.directory=D:/claude/AssabERP/Assab -C D:/claude/AssabERP/Assab status --short --branch`. No global Git configuration was changed. Existing root `BASELINE-DISCOVERY.md` is the earlier discovery report; the eight files in this directory form the S1-01 review package.

## Authority and instructions

The four files in `project-docs` were inventoried. Execution protocol, business rules, and sprint plan were read; schema overview/navigation and relevant table/migration sections were retrieved selectively. The 2.28 MB schema appendix was not repeatedly loaded. Source-projected schema is checked against repository migrations/models, not a live database. Initial discovery compared all 283 appendix migration hashes with checked-out migrations using LF-normalized contents: 283 matches, no missing/different files. This is source consistency evidence only.

| Workspace source | SHA-256 |
|---|---|
| ASSAB_AGENT_EXECUTION_AND_REVIEW_PROTOCOL.md | A96607441BA525F277880225746DE3B61E5CC412B8274CDDC0B26398C8717051 |
| ASSAB_DATABASE_SCHEMA.md | 9DB0105B32C601F96E9EAE5AE44BFB19E8776EEF190B8C7221F3B2733EB4A6D4 |
| Assab-ERP-Cash-Cycle-Business-Rules-v2.0-EN.md | 7BB57AD79B520B1C46C6793AC6396C43024D6F25B30508049A1FAAE25C88EEB6 |
| Assab-ERP-Sprint-01-Agent-Implementation-Plan.md | 37583220BE76F3AB688BFDA3172070E07A65ED0B44507AEF97592344401F37E8 |

Mohamed's approved BR-01–BR-25 define requirements. Current code/tests establish current behavior. Mahmoud owns technical review and acceptance. The user's current instruction explicitly overrides protocol/sprint requirements to commit, push, or create PRs after tasks: none is authorized in this run. Machine changes and acquisition of missing tools are also outside this run. Historical documents are navigation aids, never reset instructions.

Applicable `Assab/CLAUDE.md`: Controllers → FormRequests → Services → Repositories → Models, existing module layout and API envelopes; authorization and tenant scoping; atomic multi-table writes with locks; additive reversible migrations without editing shipped migrations; shared Support classes and dependency injection. Do not introduce app/Domain/Actions or strict_types wholesale. PHPStan is not installed; Pint/Pest are the relevant project gates. High-impact financial/security implementation requires the concrete blueprint at S1-05 before implementation.

No AGENTS.md was found in the workspace/ancestor instruction search or cloned repository file inventories. Nested `Assab/work-flow/CLAUDE.md` and `work-flow/claude-workflow-laravel/CLAUDE.md` are generic templates scoped to those directories; they do not replace root project conventions. The local laravel-feature skill's project overrides and laravel-code-review checklist were read and applied to discovery/self-review. Generic checklist assumptions about Actions, strict_types, or PHPStan are superseded by root instructions. Constitution `.specify/memory/constitution.md` v1.2 describes layering, policies, testing/performance goals, but its Laravel 11/app/Modules references differ from the actual Laravel 12/Modules checkout. App README references an absent constitution: recorded, not recreated in the read-only repository. No dashboard-specific CLAUDE.md/AGENTS.md was found.

## Current runtime and isolation

Portable PHP 8.4.26, Composer 2.10.3 and pnpm 10.34.6 are installed under the workspace `.tools/s1-01` directory. Locked backend/dashboard dependencies and PHP platform requirements passed earlier checks. Node v22.22.2 and Corepack 0.34.6 are the recorded host runtime. No dependency or lockfile change is part of this delivery.

MySQL 8.4.11 at `127.0.0.1:3310` uses `.tools/s1-01/mysql-local/data`. `assab_s1_local` is the original migrated baseline, and the ignored Backend `.env` selects its DML-only runtime account. `assab_s1_test` is a separate disposable schema; its account has schema-scoped DDL/DML rights required by the existing `RefreshDatabase` tests and cannot read the baseline or `mysql.user`. Credentials remain outside the repository in protected local files; tests load their password process-locally. No credential value is part of the proposed commits. The older 3307/3308 instances were not accessed during focused testing or this delivery review.

## CURRENT TEST SAFETY GUARD

Before running any focused PHPUnit test that uses `RefreshDatabase`, follow the fail-fast procedure in [verification.md](verification.md#current-test-safety-guard). It resolves Laravel's effective default connection, rejects `assab_s1_local`, non-loopback hosts, alternate ports, URL overrides, and every database name except `assab_s1_test`, then makes a read-only connection check. `RefreshDatabase` / `migrate:fresh` is authorized only against `assab_s1_test`. `assab_s1_local` is the migrated baseline and must never be refreshed. Keep DB credentials process-local; the guard does not display them.

The six test files ran in separate processes against the disposable schema. Each process refreshed only that schema, and teardown rolled back fixtures. Both schemas retained 283 migration records and zero nonempty business tables after testing. The recorded baseline inventory is 212 base tables, two views, two procedures and 179 foreign keys; only the migration repository contains rows. See verification.md for evidence limits and individual test results.

## Review and remaining boundaries

S1-01 is Ready for review, not Accepted. The test results establish existing behavior; they do not establish full BR-01–BR-25, A01–A18 or AC-01–AC-21 acceptance. API-contract UNKNOWN/REQUIRES VERIFICATION entries and source-identified financial gaps remain for Mahmoud's review and later authorized tasks. Dashboard typecheck passed previously; a Dashboard build/dev-server check and complete API-to-UI acceptance are not claimed.

The only code diff is removal of the stale BranchFactory field. The Purchase test-data seeder's remaining reference is a separate backlog finding in task-register.md. Deployment requires no new migration, configuration, queue restart or cache clear for this fixture/docs change. No commit, push, PR or S1-02 work has occurred. Historical setup/recovery proposals are archived in their own documents and must not be executed as current instructions.

# ASSAB BRC v3.4 — Isolated Backend Regression CI

Status: CI/config only. No application source, migration, business rule or deployment
setting is changed by this directory, `scripts/ci/`, `.github/actions/brc34-sqlite-setup/`
or `.github/workflows/assab-brc34-regression.yml`.

Immutable lower bounds (never the thing under test):

* accepted backend baseline `57bd40c39d8659a571dbfb44d1c1d651a314bf16` (ancestor of the anchor);
* accepted anchor = Staging integration candidate `bc0b87c39d8659a571dbfb44d1c1d651a314bf16`
  (tree `ce044ee1c153750955d9e0f23c4e8fac47cf8fcc`).

The gates test the **actual commit that triggered the run** (push head, or pull-request head —
not the synthetic merge ref). That commit only has to descend from the anchor.

## Gates

| Gate | Trigger | Scope | Database |
|---|---|---|---|
| FAST | every push to `integration/shift-cycle-staging-candidate-*` and `task/brc34-**`; every PR into `integration/shift-cycle-staging-candidate-*` | helper-script unit tests + the files in `scripts/ci/brc34_fast_suite.txt` + every `tests/**/*Test.php` added/modified since the anchor | in-memory SQLite |
| FULL | after FAST passes, when selected (see below) | whole repository suite (`tests/Unit`, `tests/Feature`, `tests/NFR`) | in-memory SQLite |
| MySQL 8.4 concurrency | **not part of this workflow** — separate authorisation and proposal | — | — |

FULL is selected by any of: a push to the Candidate branch (always); `[full-sqlite]` in the tested
commit message (push **and** pull request); label `full-sqlite` or `[full-sqlite]` in the PR title;
`workflow_dispatch` input `run_full_sqlite`. The decision and its reason are written to
`full-selection.txt` and a `::notice::` annotation. `workflow_dispatch` is only offered by GitHub for
workflow files on the default branch; the workflow does not depend on it, so no merge to `main`
is required.

## Source guard (`scripts/ci/source_guard.sh`, policy `scripts/ci/source_scope_policy.txt`)

1. checked-out HEAD must equal the tested commit;
2. the anchor must exist with the recorded tree, the baseline must be its ancestor, and the tested
   commit must descend from the anchor;
3. approved application corrections are **allowed**; changed files are counted by category
   (ci / tests / docs / application) as evidence;
4. a hard failure if any path changed since the anchor is denied by the policy: environment files
   (except `.env.example`), deployment/infrastructure definitions (Railpack, Docker, Coolify), the
   Staging workflows, `database/migrations` + `database/seeders` (core and modules), and dependency
   manifests. Changing the policy file itself is a reviewed, visible change.

## Isolation (fail-closed, three layers + forced config)

1. `scripts/ci/assert_isolated_env.sh` — before any application code runs: GitHub-hosted
   runner, `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, array
   cache/session, sync queue, the CI-only `APP_KEY`, no `DB_HOST`/`DB_URL`/credentials, no
   Staging/Production/Coolify marker in any variable name or value, no `secrets.`/`vars.`
   reference in the task workflow. Values are never printed.
2. `scripts/ci/assert_laravel_sqlite_memory.php` — boots the real application and checks the
   *resolved* configuration: default connection `sqlite`, database `:memory:`, every
   configured connection has only a loopback/empty host and no URL, no Staging/Production
   database name, then (only if all static checks passed) confirms the live driver and an
   empty `PRAGMA database_list` file. It never contacts a non-SQLite target.
3. `scripts/ci/phpunit-isolation-bootstrap.php` — PHPUnit bootstrap of the generated
   configuration; aborts before any test class loads if the process is not on SQLite memory.
   In the top-level process any `DB_HOST`/`DB_USERNAME`/`DB_PASSWORD`/`DB_URL` aborts. A test
   annotated `#[RunInSeparateProcess]` (`IdempotencyCommitRecoveryTest`) runs in a child that
   inherits the values Laravel's Dotenv loader copied from the committed `.env.testing` into
   the worker environment (inert: `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` are already
   fixed and Dotenv never overrides them). The child is recognised by
   `ASSAB_CI_ISOLATION_VERIFIED=1`, exported by this bootstrap only after the strict check
   passed and rejected by the shell guard if pre-set; in such a child only a loopback
   `DB_HOST` is tolerated and DSN-style variables are never tolerated.
4. `scripts/ci/make_phpunit_config.py` derives the CI configuration from the repository's own
   `phpunit.xml` and marks the database/cache/session/queue/mail `<env>` entries
   `force="true"`, so no outside value can redirect the test process.

## Evidence

Every job uploads an artifact (`assab-brc34-<gate>-<run_id>-<attempt>`) containing the JUnit
file, the console log (with skip reasons), `*.summary.json|md`, guard outputs,
`source-guard.txt` (tested SHA/tree, candidate ancestry, files changed since the candidate),
runtime versions, the generated PHPUnit configuration and a SHA-256 manifest. The same numbers
are written to the job summary and to `::notice::` annotations.

Skips are never counted as passes. `DB_CONCURRENCY_OPT_IN` skips are MySQL/MariaDB-only tests
that this SQLite workflow cannot and does not execute; they stay `NOT_RUN` evidence for the
separate MySQL gate. `SQLITE_DRIVER_LIMITATION` skips are tests that detect at run time that the
SQLite driver does not provide a behaviour (e.g. column-length enforcement) and skip themselves;
they are likewise not passes. Any skip whose reason matches no known category is reported as
`UNCLASSIFIED` with a warning annotation instead of being hidden.

A green run is **not** business acceptance.

## Changing the FAST list

Add or remove files in `scripts/ci/brc34_fast_suite.txt`. The generator fails if a listed file
is missing. Removing a failing test to obtain a green run is not permitted.

## Local verification of the helper scripts

    python3 -I -m unittest discover -s scripts/ci -p 'test_*.py'

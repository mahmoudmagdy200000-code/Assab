# ASSAB BRC v3.4 — Isolated Backend Regression CI

Status: CI/config only. No application source, migration, business rule or deployment
setting is changed by this directory, `scripts/ci/`, `.github/actions/brc34-sqlite-setup/`
or `.github/workflows/assab-brc34-regression.yml`.

Accepted candidate under test: `bc0b87c39d8659a571dbfb44d1c1d651a314bf16`
(tree `ce044ee1c153750955d9e0f23c4e8fac47cf8fcc`).

## Gates

| Gate | Trigger | Scope | Database |
|---|---|---|---|
| FAST | every push to `task/brc34-isolated-regression-ci-*`; every PR into `integration/shift-cycle-staging-candidate-20261016` | the files listed in `scripts/ci/brc34_fast_suite.txt` | in-memory SQLite |
| FULL | only after FAST passes, and only when selected: `[full-sqlite]` in the pushed head commit message, label `full-sqlite` or `[full-sqlite]` in the PR title, or `workflow_dispatch` input `run_full_sqlite` | whole repository suite (`tests/Unit`, `tests/Feature`, `tests/NFR`) | in-memory SQLite |
| MySQL 8.4 concurrency | **not part of this workflow** — separate authorisation and proposal | — | — |

`workflow_dispatch` is only offered by GitHub once the workflow file exists on the default
branch. It is declared for later use; the task branch relies on `push` / `pull_request`.

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
separate MySQL gate.

A green run is **not** business acceptance.

## Changing the FAST list

Add or remove files in `scripts/ci/brc34_fast_suite.txt`. The generator fails if a listed file
is missing. Removing a failing test to obtain a green run is not permitted.

## Local verification of the helper scripts

    python3 -I -m unittest discover -s scripts/ci -p 'test_*.py'

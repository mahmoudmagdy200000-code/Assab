# ASSAB ERP — Staging API Diagnostics + Manual AI Draft Fix PR

**Status:** committed on a personal-fork task branch for review. **NOT ACTIVE**: nothing here has run on GitHub. No release acceptance is implied.
Evidence labels used below: `SOURCE_INSPECTION_ONLY`, `REVIEWER_EXECUTED`, `NOT_RUN`, `BLOCKED`.

## What it is

| Piece | File | Trigger | Token |
|---|---|---|---|
| GET-only Staging smoke + optional read-only AI triage | `.github/workflows/assab-staging-api-diagnostics.yml`, `scripts/staging_api_probe.py` | `workflow_dispatch`, `repository_dispatch` type `assab-staging-deployed` | `contents: read` |
| Manual AI proposal → Draft PR | `.github/workflows/assab-staging-fix-pr.yml`, `scripts/ai_output_guard.py` | `workflow_dispatch` only | propose job `contents: read`; draft-pr job `contents: write`, `pull-requests: write` |
| Offline tests | `scripts/test_staging_api_probe.py`, `scripts/test_ai_output_guard.py` | run inside the diagnostics workflow | n/a |

The existing `.github/workflows/assab-backend-ci.yml` (S1-12 trial CI) is untouched.

## Endpoint contract (anonymous client)

| Route | Expected | Verified against source (`SOURCE_INSPECTION_ONLY`) |
|---|---|---|
| `/up` | 200 | `bootstrap/app.php` `withRouting(health: '/up')` |
| `/api/aggregators` | 401 or 403 | `Modules/Aggregator/routes/api.php`: `Route::prefix('aggregators')->middleware(['auth:sanctum'])`, loaded at `/api` by `routes/api.php` and at `/api/v1` by the module provider |
| `/api/aggregators/available` | 200 | same file, public route declared before the protected group; reads the `aggregators` table through `Aggregator::active()` |

Result classes: **PASS** (all expected), **FAIL** (a response with an unexpected status), **BLOCKED** (no HTTP response: DNS/TCP/TLS/timeout/invalid URL). Exit codes 0 / 1 / 2. A fourth, **informational** control check is recorded but never changes the verdict. AI triage status is **COMPLETED / NOT_RUN / BLOCKED / FAIL** in `diagnostic-status.json`.

## Staging HTTP 500 on `/api/aggregators` — what is and is not known

- **Confirmed from source** (`SOURCE_INSPECTION_ONLY`, laravel/framework v12.33.0 as locked): `Handler::unauthenticated()` returns JSON 401 only when `$request->expectsJson()`; otherwise it evaluates `route('login')`. The repository defines no route named `login` (no `->name('login')` anywhere under `routes/`, `Modules/*/routes`, `app/`), and `bootstrap/app.php` neither overrides `shouldRenderJsonWhen` nor sets a guest redirect.
- **Hypothesis (not runtime-confirmed):** the failing request was `GET http://127.0.0.1/api/aggregators` without `Accept: application/json`; the unauthenticated redirect then throws `RouteNotFoundException` → HTTP 500. If so, the same route returns 401 for a JSON client.
- **How the automation tests this:** the probe requests the route twice, once with `Accept: application/json` (the verdict) and once without (informational control). Verdict 401/403 plus control 500 confirms the hypothesis at runtime; a 500 on the JSON request means the cause is something else and needs a redacted `laravel.log` exception class.
- **Fix location caveat:** the natural fix is in `bootstrap/app.php` (JSON for `api/*`, or a guest redirect), which is outside the Fix-PR allowlist and changes authentication behaviour. It is a human decision, not an automated one.

## Fix-PR guard (`scripts/ai_output_guard.py`, run only from a trusted checkout)

The AI may only change `.php` files under `app/`, `Modules/`, `routes/`, `tests/`: at most 6 files, ≤ 80000-byte patch, no deletion/rename/mode change/symlink/binary, no database/config/migration/seeder/vendor/bootstrap/workflow paths, no whitespace errors, base SHA must equal the verified SHA, no unsafe local git config. `.env*` files are removed from the AI workspace and excluded from the patch.
Results: `OK` (draft PR allowed), `NO_DIFF`, `REJECTED`, and `NEEDS_HUMAN_REVIEW` when the path or any changed line concerns authorization, middleware, policy, tenant, sanctum, permissions, shifts, cash custody, handover, ledger, payments, idempotency, transactions, VAT, commission, etc. The last two never open a PR; the patch stays in the run artifact. The `draft-pr` job re-applies the patch to a clean checkout and re-runs the guard; the patch must be byte-identical. Nothing from the patch is executed (no composer, php, artisan, tests).

## Activation gate (what must happen before it works)

1. **Default-branch requirement.** GitHub only offers `workflow_dispatch` for a workflow file that exists on the repository's default branch, and `repository_dispatch` runs the workflow from the default branch ([GitHub Docs](https://docs.github.com/es/enterprise-server@3.8/actions/using-workflows/triggering-a-workflow), older Enterprise Server 3.8 page; re-check the current docs). Publishing to a task branch or a fork does not activate it. This task does **not** modify any default branch.
2. **Separate approvals required:** an upstream pull request from the task branch, review, and a merge into upstream `main` (or an explicitly approved alternative). The workflows check out the Staging branch only as *source under diagnosis* and take their scripts from the dispatching ref (the default branch), so the scripts must be merged together with the workflows.
3. The workflows are guarded with `github.repository == 'mahmoudmagdy200000-code/Assab'`, so in the personal fork they are skipped by design.
4. Repository secret **`OPENAI_API_KEY`** (Settings → Secrets and variables → Actions). Without it: smoke test runs; AI triage is reported `BLOCKED` with this exact requirement; the Fix-PR workflow stops with `BLOCKED`.
5. Settings → Actions → General → Workflow permissions: tick **Allow GitHub Actions to create and approve pull requests** (for Draft PR creation only; keep the default token read-only).
6. `repository_dispatch` after a deploy needs a trusted relay holding a token with write access to the repository; Coolify has no preconfigured GitHub dispatch. Not set up, needs its own security review. Until then use *Run workflow*.
7. Operator confirmations: the Staging hostname pinned in `scripts/staging_api_probe.py` (taken from the package, **unverified**); that sending the repository source (not logs, not `.env*`) to OpenAI is acceptable; the Fix-PR actor allowlist (`mahmoudmagdy200000-code`); the default-branch name (`main` at inspection).

## Security properties (review result)

- No `pull_request_target`, `workflow_run`, schedule, or untrusted-code execution with write credentials. Third-party actions are pinned to full commit SHAs (checkout v4.4.0, upload-artifact v4.6.2, download-artifact v4.3.0, create-pull-request v7.0.11, codex-action v1.13). `@openai/codex` is installed by the action at its latest version (no pin supplied).
- Operator input (`problem_summary`, SHA) reaches the shell only through `env:`; no `${{ }}` inside any `run:`. It is length/format checked and refused if it looks like a secret. It reaches the AI only inside a delimited "untrusted data" block.
- Probe: pinned host, GET only, no credentials, no redirects, no bodies stored, exceptions reduced to class names. AI advice is secret-redacted and truncated before it is stored as an artifact.
- The read-only AI step uses `permission-profile: ':read-only'` and `safety-strategy: drop-sudo`; the action itself refuses actors without repository write access.
- Residual risks: repository source is sent to OpenAI; `.git` history is readable by the AI; the AI could in theory produce a harmful change inside the allowed paths that evades the keyword screen — hence Draft-only, human review, no CI on bot PRs (below), no auto-merge.

## Limitations

- PRs created with `GITHUB_TOKEN` do not trigger other workflows; run CI manually or use an approved CI identity.
- The probe checks statuses, not application correctness; the two anonymous GETs touch the Staging web app and its logs.
- Out of scope, separate tasks: missing PHP `intl` extension, the first-start SQLite cache error, API Inspector exposure, Coolify runtime log collection.
- Nothing has been observed running on GitHub: live smoke, AI triage and Draft PR creation are all `NOT_RUN`.

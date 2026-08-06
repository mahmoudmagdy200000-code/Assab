---
name: laravel-code-review
description: Run a self-review checklist before completing a backend task. Use when the user says "task done", "work is done", "finished", "review this", or when verifying code quality, security, and database safety before approval.
allowed-tools: Read Grep Glob Bash(git diff *) Bash(git status *) Bash(./vendor/bin/pint --test *) Bash(./vendor/bin/phpstan *) Bash(php artisan test *) Bash(php artisan route:list *)
---

# Code Completion Self-Review — Laravel Backend

Run this checklist before marking any task as done. This is a read-only review — do not modify code during this step.

## Correctness

- [ ] The root cause is correctly identified and addressed (not just symptoms).
- [ ] The solution handles the specific problem described in the task.
- [ ] Edge cases handled: null, empty collection, zero, duplicate submit, concurrent request.
- [ ] No silent failures — every failure path throws a typed exception that reaches the handler.

## Architecture Compliance

- [ ] Layer boundaries respected: `HTTP → Application → Domain → Persistence`.
- [ ] Controller is thin — no queries, no business branching, one Action call.
- [ ] Actions depend only on interfaces; no `Request`/`Response`/`abort()` inside the domain.
- [ ] Models contain only casts, relations, and scopes.
- [ ] Shared logic placed in `app/Support/`, not duplicated across modules.

## Database & Performance

- [ ] No N+1: every relation used in the response is eager loaded, and Resources use `whenLoaded()`.
- [ ] No unbounded query — list endpoints paginate; bulk work uses `chunkById`/`cursor`.
- [ ] Only the needed columns are selected on large tables.
- [ ] New/changed `where`, `orderBy`, and foreign key columns are indexed.
- [ ] Multi-table writes wrapped in a transaction; no HTTP/mail/dispatch inside the transaction.
- [ ] Migration is reversible and does not edit an already-shipped migration.
- [ ] No blocking work in the request cycle that belongs on a queue.

## Security

- [ ] Every state-changing endpoint is authorized (Policy/Gate) — ownership checked server-side.
- [ ] Queries are scoped by tenant/user; no object can be reached by guessing an ID (IDOR).
- [ ] `$request->validated()` or a DTO used — never `$request->all()` into `create`/`update`/`fill`.
- [ ] No hardcoded secrets; no `env()` outside `config/`.
- [ ] No raw SQL with interpolated input — bindings used.
- [ ] No sensitive data in logs, exception messages, or API responses.
- [ ] Rate limiting present on public and auth endpoints.
- [ ] File uploads validated by mime + size, stored outside the public root unless intended.

## API Contract

- [ ] Response goes through a Resource — no raw model or array returned.
- [ ] No existing response key renamed or removed (breaking change).
- [ ] Correct status codes (201 on create, 204 on delete, 422 on validation, 403 vs 404 deliberate).
- [ ] Route registered under the versioned prefix with auth + throttle middleware.

## Tests

- [ ] Feature test covers happy path + unauthorized + validation failure.
- [ ] Bug fix includes a test that fails without the fix.
- [ ] External calls faked (`Http::fake`, `Queue::fake`, `Mail::fake`) — no live network in tests.
- [ ] `php artisan test` passes.

## Code Quality

- [ ] `declare(strict_types=1);` present; parameters, properties, and returns typed.
- [ ] No `dd()`, `dump()`, `ray()`, `var_dump`, leftover `Log::debug`, or commented-out code.
- [ ] No unused imports or dead code.
- [ ] `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse` pass.
- [ ] Naming follows the project conventions table in `CLAUDE.md`.

## Output

After completing the checklist, provide a brief summary:

1. **What** was changed
2. **Why** it was changed
3. **Why** the solution is safe and correct
4. **Deploy notes** — migrations to run, env vars to add, queue workers to restart, cache to clear

If any checklist item fails, flag it and suggest a fix before proceeding.

# CLAUDE.md

<!--
Golden Test: "Would removing this rule cause Claude to make mistakes?"
If not — cut it. Don't restate defaults Claude already knows.

PROJECT DEFAULTS — fill these in per project, then delete this comment:
  Laravel: 13.x   |   PHP: 8.3+   |   Layout: Modular (app/Domain/*)
  Tests: Pest     |   Static analysis: Larastan lvl 6   |   Style: Pint
-->

---

# Section A — General Engineering Rules

## 1) Architecture & Separation of Concerns (YOU MUST FOLLOW)

- Follow the project's architecture layer boundaries strictly: `HTTP → Application → Domain → Persistence`
- Never bypass layers or mix responsibilities
- The HTTP layer (Controllers, Requests, Resources, Middleware) has ZERO business logic — only validation, authorization, delegation, and serialization
- Business logic lives in Actions / Domain services
- Data access (Eloquent, external APIs, cache, storage) lives in the persistence/infrastructure layer
- Do not introduce new abstractions or patterns without justification

## 2) Shared Code (IMPORTANT)

- Any reusable logic, trait, helper, enum, or value object used in 2+ places goes in `app/Support/`
- Check `app/Support/` before creating new shared code — never duplicate across modules

## 3) Error Handling

- Errors flow cleanly across layers — never skip layers
- Handle null, empty, and failure paths explicitly — no silent failures, no `try { } catch (\Throwable) { }` swallowing
- Catch infrastructure exceptions at the boundary (repository / HTTP client), map them to typed domain exceptions
- Never return `['error' => ...]` arrays from business logic — throw a typed exception

## 4) Change Discipline

- Make the smallest change that solves the problem
- Fix root causes, not symptoms
- Don't refactor unrelated code unless explicitly requested
- Never break existing functionality, API response shapes, routes, or DB contracts unless explicitly instructed
- Read relevant code before modifying it — state assumptions when unclear

## 5) Dependencies

- Don't add new Composer packages without justification
- Prefer first-party Laravel packages over third-party ones
- Any new package must be: latest stable, actively maintained, compatible with the project's Laravel/PHP version

## 6) Security

- Never hardcode secrets, tokens, or credentials
- Never log sensitive information (passwords, tokens, national IDs, card data, full request bodies)
- Validate ALL external input at the boundary — never trust a request field, route param, or webhook payload
- Authorize every state-changing endpoint — never rely on an ID coming from the client
- Proactively flag security risks when spotted

## 7) Testing

- Write tests for Actions (domain) and repositories (data)
- Every API endpoint gets at least one feature test: happy path + auth failure + validation failure
- Bug fixes must include a reproducing test
- Tests must be deterministic — no flaky, time-dependent, or order-dependent tests
- One behavior per test case

## 8) Workflow (Mandatory)

- Before creating any new feature or endpoint → invoke the `/laravel-feature` skill first for scaffolding and architecture reference
- Before writing any query, relation load, or list endpoint → check the `/laravel-query-performance` skill
- Before marking any task done → run the `/laravel-code-review` skill
- After task approved → use the `@git-expert` agent for branch, commit, and PR output

## 9) Agents — Proactively Suggest (YOU MUST FOLLOW)

You MUST proactively suggest the appropriate agent when the situation matches. Do not wait for the user to ask.

- `@debugger` — When a bug, 500 error, exception, failed job, or unexpected behavior is encountered
- `@code-reviewer` — After `/laravel-code-review` passes, ALWAYS suggest running `@code-reviewer` for a deeper independent review before proceeding to PR
- `@test-writer` — When code is changed or added without corresponding tests, or when coverage is missing
- `@migration-reviewer` — ALWAYS when a migration file is created or modified, before it is committed
- `@git-expert` — When it's time to create a branch, commit, or PR. Also for merge conflicts, rebases, or any complex git situation

---

# Section B — Laravel / PHP Specific Rules

<!--
Follow PSR-12 + Laravel Pint defaults and standard Laravel conventions.
Rules below only cover things that OVERRIDE defaults or encode project decisions.
-->

## 1) Controllers (YOU MUST FOLLOW)

- Controllers are **thin**: authorize → validate (FormRequest) → call ONE Action → return a Resource
- Max ~15 lines per controller method. If it's longer, logic belongs in an Action
- ZERO Eloquent queries in controllers. ZERO `if` business branching. ZERO array shaping
- Prefer single-action invokable controllers for non-CRUD endpoints
- Never pass `Request` into an Action — convert to a DTO at the HTTP boundary

## 2) Actions (the unit of business logic)

- One Action = one business operation = one public method `handle()`
- Actions depend ONLY on repository/service **interfaces** and other Actions — never on `Request`, `Response`, session, or facades that hide dependencies
- Actions are framework-agnostic where possible: no `response()`, no `redirect()`, no `abort()`
- Actions return a DTO, a Model, a collection, or `void` — never a JSON response
- Location: `app/Domain/{Module}/Actions/{Verb}{Noun}Action.php`

## 3) Validation & Authorization

- ALWAYS use a FormRequest — never validate inline in the controller for anything beyond a single trivial field
- Never pass `$request->all()` into `create()` / `update()` / `fill()` — use `$request->validated()` or a DTO
- Authorization goes in a **Policy**, invoked via `authorize()` in the FormRequest or `Gate`/`$this->authorize()` in the controller
- Every model with an owner MUST have a Policy. Ownership is checked server-side, never inferred from the payload
- Scope queries by tenant/user at the query level (global scope or explicit `where`), not after fetching

## 4) Eloquent Discipline (IMPORTANT)

- No queries outside repositories/Actions — never in controllers, Resources, Blade, or Observers doing business work
- ALWAYS eager load relations used in the response (`with()`), and only the columns needed (`select`)
- `Model::preventLazyLoading(! app()->isProduction())` is enabled — a lazy load in dev is a hard error, fix it, don't disable it
- Never `Model::all()` on a table that grows. Paginate (`paginate` / `cursorPaginate`) or `chunkById`
- Models hold: casts, relations, scopes, accessors. Models do NOT hold business logic, HTTP calls, or notifications
- Use `$fillable` explicitly (or `$guarded = ['id']` project-wide — pick one and stay consistent)

## 5) Database & Transactions

- Any operation that writes to 2+ tables runs inside `DB::transaction()`
- NEVER do HTTP calls, file uploads, mail, or `sleep` inside a transaction
- Dispatch jobs/events that depend on committed data with `->afterCommit()` (or `$afterCommit = true` on the job)
- Every foreign key gets an index. Every column used in a `where`/`orderBy` on a growing table gets an index
- Migrations are additive and reversible. NEVER edit a migration that has already run in staging/production — write a new one
- Data backfills go in a separate migration or a dedicated command — never mixed with schema changes on large tables

## 6) API Contract

- All API responses go through an **API Resource** — never `return $model` or `return response()->json($array)` from a controller
- Routes are versioned: `routes/api.php` → `Route::prefix('v1')` with module route files
- Response envelope is consistent across the whole API (see `/laravel-feature` skill)
- Changing an existing response key = breaking change. Add, never rename or remove, without an explicit version bump
- Every public endpoint has rate limiting (`throttle` middleware)

## 7) Error Handling Contract

- **Persistence layer**: catch `QueryException`, `ConnectionException`, HTTP client errors → map to typed domain exceptions
- **Domain layer**: throw typed exceptions extending `app/Domain/{Module}/Exceptions/` base — each carries a stable `errorCode` and HTTP status
- **HTTP layer**: exceptions are rendered centrally in `bootstrap/app.php` (`->withExceptions()`) into the standard JSON envelope
- Never render an exception message from a third-party library directly to the client

## 8) Dependency Injection

- Use Laravel's service container. Constructor injection only — no `app()`, `resolve()`, or facades inside Actions and domain code
- Bind interfaces to implementations in a dedicated `DomainServiceProvider` (one `bind` block per module)
- Repositories, gateways, and clients are bound as `singleton` when stateless
- Facades are allowed only in the HTTP layer, jobs, and commands

## 9) Config, Env & Runtime

- `env()` is FORBIDDEN outside `config/*.php` files — it returns `null` once `config:cache` runs in production
- Read everything through `config('module.key')`
- Any new setting gets a config file entry + `.env.example` line in the same change
- No `dd()`, `dump()`, `ray()`, `var_dump`, or `Log::debug` left in committed code

## 10) Queues & Async

- Anything slower than ~300ms that the user doesn't need to wait for goes to a queue (mail, PDF/exports, external API sync, notifications)
- Jobs must be **idempotent** — they will run more than once
- Every job sets `$tries`, `$backoff`, and implements `failed()`
- See the `/laravel-job-queue` skill before creating any job, event listener, or scheduled command

## 11) Types & Static Analysis

- `declare(strict_types=1);` at the top of every PHP file
- Type every parameter, property, and return value. `mixed` and untyped arrays require a docblock (`@return array<int, UserDto>`)
- Use `readonly` DTOs and backed enums instead of loose arrays and magic strings
- Code must pass `./vendor/bin/pint` and `./vendor/bin/phpstan analyse` before it is considered done

## 12) Naming Conventions

| Thing | Convention | Example |
|---|---|---|
| Model | singular, StudlyCase | `Invoice` |
| Table | plural, snake_case | `invoices` |
| Controller | `{Noun}Controller` | `InvoiceController` |
| Action | `{Verb}{Noun}Action` | `IssueInvoiceAction` |
| DTO | `{Noun}Data` | `InvoiceData` |
| FormRequest | `{Verb}{Noun}Request` | `StoreInvoiceRequest` |
| Resource | `{Noun}Resource` | `InvoiceResource` |
| Job | `{Verb}{Noun}Job` | `SendInvoiceJob` |
| Event | past tense | `InvoiceIssued` |
| Migration | descriptive verb | `add_status_to_invoices_table` |
| Route name | `{module}.{action}` | `invoices.store` |
| Test | `{Class}Test` | `IssueInvoiceActionTest` |

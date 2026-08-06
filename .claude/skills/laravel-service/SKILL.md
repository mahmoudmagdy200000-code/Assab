---
name: laravel-service
description: Scaffold a single-purpose Service method (the unit of business logic in this repo) with its repository dependency and typed domain exception. MUST be used whenever a feature, endpoint, command, or job requires business logic, orchestration of multiple writes, external API calls, state transitions, or any rule beyond a plain CRUD passthrough. Applies automatically when creating controllers, artisan commands, or listeners that would otherwise contain business logic.
allowed-tools: Read Write Edit Glob Grep
---

# Scaffold a Service method (Assab, Laravel 12 / PHP 8.2)

> **Project note.** The upstream bundle called this an *Action* in `app/Domain/{Module}/Actions/`. This repo does **not** use that layout. The unit of business logic here is a **Service method** inside the module: `Modules/{Module}/app/Services/{Noun}Service.php`. Everything below is the Action discipline translated to that layout — do not create `app/Domain/` or `app/Actions/`.

Generate the Service method, its repository dependency, and (if needed) its typed exception. **No business logic in controllers, models, jobs, or listeners — they all delegate to a Service.**

## Rules

- One public method = one business operation, named for the operation (`issueInvoice`, `confirmReceipt`)
- Constructor-injected dependencies only — **interfaces** where the module has one (`{Module}RepositoryInterface`), else the concrete repository
- No facades inside a Service (project rule). Inject `DatabaseManager`/`ConnectionInterface` or the repository instead of reaching for `DB::`/`Auth::`/`Log::`
- No framework coupling: no `Request`, `Response`, `response()`, `abort()`, `redirect()`, `session()`, `auth()`
- Input is the FormRequest's `validated()` array (or a `readonly` DTO for operations with many fields). Never accept a `Request` object, never `$request->all()`
- The actor (user/branch/company) is an explicit parameter — never pulled from `auth()` inside the Service
- Output is a Model, Collection, array, or `void` — never a JSON response, never `['error' => ...]`
- Failures throw a typed exception from `Modules/{Module}/app/Exceptions/` — never return `false` or `null`-as-error
- Tenant scoping happens in the query (repository scope / global scope), not after fetching

## Service

`Modules/{Module}/app/Services/{Noun}Service.php`

```php
<?php

namespace Modules\{Module}\Services;

use Illuminate\Database\ConnectionInterface;
use Modules\{Module}\Exceptions\{Module}Exception;
use Modules\{Module}\Models\{Noun};
use Modules\{Module}\Repositories\{Module}RepositoryInterface;

class {Noun}Service
{
    public function __construct(
        private readonly {Module}RepositoryInterface $repository,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated payload
     */
    public function {verb}{Noun}(array $data, User $actor): {Noun}
    {
        // 1) Guard — business preconditions (NOT input validation; that is the FormRequest's job)
        $model = $this->repository->findForActor($data['id'], $actor)
            ?? throw {Module}Exception::notFound($data['id']);

        if (! $model->status->canTransitionTo({Noun}Status::Target)) {
            throw {Module}Exception::invalidTransition($model->status, {Noun}Status::Target);
        }

        // 2) Mutate — everything that must succeed together goes in one transaction
        $result = $this->db->transaction(function () use ($model, $data): {Noun} {
            $model->update([...]);
            $model->children()->createMany([...]);

            return $model->refresh();
        });

        // 3) Side effects — AFTER commit, never inside the transaction
        {Noun}{PastTense}::dispatch($result->id, $actor->id);

        return $result;
    }
}
```

## Optional DTO (many fields, or reused across entry points)

`Modules/{Module}/app/Support/{Verb}{Noun}Data.php`

```php
final readonly class {Verb}{Noun}Data
{
    public function __construct(
        public string $someId,          // UUIDs in this project — string, not int
        public string $someField,
        public ?CarbonImmutable $someDate = null,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            someId: $validated['some_id'],
            someField: $validated['some_field'],
            someDate: isset($validated['some_date'])
                ? CarbonImmutable::parse($validated['some_date'])
                : null,
        );
    }
}
```

Use an array for 1–4 fields. Reach for a DTO when the payload is wide, is built by more than one entry point, or carries derived values.

## Transaction & Side-Effect Contract (IMPORTANT)

| Inside the transaction | Outside / after commit |
|---|---|
| Model writes, relation writes | HTTP / external API calls |
| Balance and counter updates | Mail, SMS, push, Pusher broadcasts |
| `lockForUpdate()` reads | Job dispatch, event dispatch |
| | File uploads / PDF generation |

- Keep transactions **short** — every extra millisecond holds row locks
- Dispatch with `->afterCommit()`, or set `public $afterCommit = true;` on the job
- Events carry **IDs**, not hydrated models — the listener re-fetches fresh state
- For money, counters, or stock: `lockForUpdate()` inside the transaction, or a DB-level atomic `increment()`

## Composing Services

- A Service MAY call another Service (inject it) — this is the correct way to reuse business logic
- A Service MUST NOT call a controller, a FormRequest, or a Transformer/Resource
- Cross-module reuse goes through an **event** or an injected interface, not a direct `use Modules\Other\...` grab of internals
- Shared helpers used in 2+ modules go in `app/Support/`; module-local helpers in `Modules/{Module}/app/Support/`
- Nested transactions are safe (savepoints), but the outermost one owns the commit

## Where Services get called from

```php
// Controller (thin: validate → service → transformer via BaseController helper)
$model = $this->service->issueInvoice($request->validated(), $request->user());

return $this->createdResponse(new InvoiceResource($model));

// Artisan command
$this->service->syncData($this->options(), $systemUser);

// Queued job
public function handle(InvoiceService $service): void
{
    $service->issueInvoice($this->data, $this->actor);
}
```

Every entry point converts its own input into the array/DTO. The Service never learns where it was called from.

## After scaffolding, verify

- [ ] Method does one business operation and is named for it
- [ ] No `Illuminate\Http` imports; no facades inside the Service
- [ ] Dependencies are interfaces where the module defines one
- [ ] Every failure path throws a typed exception the handler can render
- [ ] Multi-table writes wrapped in a transaction
- [ ] No HTTP calls, mail, broadcasts, or job dispatch inside the transaction
- [ ] Tenant/branch/company scoping applied at the query level
- [ ] Test exists — `tests/Unit/` for the Service, `tests/Feature/` for the endpoint that calls it

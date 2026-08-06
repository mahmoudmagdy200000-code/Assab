---
name: laravel-action
description: Scaffold a single-purpose Action class with a readonly input DTO and typed domain exception. MUST be used whenever a feature, endpoint, command, or job requires business logic, orchestration of multiple writes, external API calls, state transitions, or any rule beyond a plain CRUD passthrough — even if "action" is not explicitly mentioned. Applies automatically when creating controllers, artisan commands, or listeners that would otherwise contain business logic.
allowed-tools: Read Write Edit Glob Grep
---

# Scaffold an Action + DTO (PHP 8.3+)

Generate an Action class, its input DTO, and (if needed) its typed exception. **No business logic in controllers, models, jobs, or listeners — they all delegate to an Action.**

## Rules

- One Action = one business operation = one public method `handle()`
- Constructor-injected dependencies only — **interfaces**, never concrete repositories where an interface exists
- Zero framework coupling: no `Request`, `Response`, `response()`, `abort()`, `redirect()`, `session()`, `auth()`
- Input is a `readonly` DTO. Output is a DTO, Model, Collection, or `void` — never a JSON response
- The actor (user/tenant) is an explicit constructor or `handle()` parameter — never pulled from `auth()`
- Failures throw a typed domain exception — never return `false`, `null`-as-error, or `['error' => ...]`
- Location: `app/Domain/{Module}/Actions/{Verb}{Noun}Action.php`

## Input DTO (`app/Domain/{Module}/Data/{Verb}{Noun}Data.php`)

```php
<?php

declare(strict_types=1);

namespace App\Domain\{Module}\Data;

final readonly class {Verb}{Noun}Data
{
    public function __construct(
        public int $someId,
        public string $someField,
        public ?CarbonImmutable $someDate = null,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            someId: (int) $validated['some_id'],
            someField: $validated['some_field'],
            someDate: isset($validated['some_date'])
                ? CarbonImmutable::parse($validated['some_date'])
                : null,
        );
    }
}
```

## Action (`app/Domain/{Module}/Actions/{Verb}{Noun}Action.php`)

```php
<?php

declare(strict_types=1);

namespace App\Domain\{Module}\Actions;

final readonly class {Verb}{Noun}Action
{
    public function __construct(
        private {Module}RepositoryInterface $repository,
    ) {}

    public function handle({Verb}{Noun}Data $data, User $actor): {Noun}
    {
        // 1) Guard — business preconditions (NOT input validation, that's the FormRequest's job)
        $model = $this->repository->findById($data->someId)
            ?? throw {Module}Exception::notFound($data->someId);

        if (! $model->status->canTransitionTo({Noun}Status::Target)) {
            throw {Module}Exception::invalidTransition($model->status, {Noun}Status::Target);
        }

        // 2) Mutate — everything that must succeed together goes in one transaction
        $result = DB::transaction(function () use ($model, $data, $actor): {Noun} {
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

## Transaction & Side-Effect Contract (IMPORTANT)

| Inside `DB::transaction()` | Outside / after commit |
|---|---|
| Model writes, relation writes | HTTP / external API calls |
| Balance and counter updates | Mail, SMS, push notifications |
| `lockForUpdate()` reads | Job dispatch, event dispatch |
| | File uploads / PDF generation |

- Keep transactions **short** — every extra millisecond holds row locks
- Dispatch with `->afterCommit()`, or set `public $afterCommit = true;` on the job
- Events carry **IDs**, not hydrated models — the listener re-fetches fresh state
- For money, counters, or stock: `lockForUpdate()` inside the transaction, or a DB-level atomic `increment()`

## Composing Actions

- An Action MAY call another Action (inject it) — this is the correct way to reuse business logic
- An Action MUST NOT call a controller, a FormRequest, or a Resource
- If two Actions share a chunk of logic, extract a third Action or a `Support/` service — never copy-paste
- Nested `DB::transaction()` calls are safe (Laravel uses savepoints), but the outermost one owns the commit

## Where Actions get called from

```php
// Controller
$action->handle($request->toData(), $request->user());

// Artisan command
$this->action->handle(SyncData::fromArray($this->options()), User::system());

// Queued job
public function handle(ProcessPaymentAction $action): void
{
    $action->handle($this->data, $this->actor);
}

// Listener
```

Every entry point converts its own input into the DTO. The Action never learns where it was called from.

## After scaffolding, verify

- [ ] Exactly one public method (`handle`)
- [ ] No `Illuminate\Http` imports, no facades that hide dependencies
- [ ] All dependencies are interfaces where an interface exists
- [ ] Every failure path throws a typed exception with a stable `errorCode`
- [ ] Multi-table writes are wrapped in `DB::transaction()`
- [ ] No HTTP calls, mail, or job dispatch inside the transaction
- [ ] Unit test exists at `tests/Unit/Domain/{Module}/{Verb}{Noun}ActionTest.php`

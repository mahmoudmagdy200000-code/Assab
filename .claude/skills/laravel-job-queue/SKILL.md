---
name: laravel-job-queue
description: Scaffold and review queued jobs, events, listeners, batches, and scheduled commands in Laravel. Use whenever work is moved off the request cycle — sending mail or notifications, calling an external API, generating files or reports, syncing data, webhooks, imports, or any recurring task. Triggers on "job", "queue", "background", "cron", "schedule", "webhook", "export", "import", "notification".
allowed-tools: Read Write Edit Glob Grep Bash(php artisan queue:*) Bash(php artisan make:job *) Bash(php artisan schedule:list *)
---

# Queues, Jobs & Scheduling — Laravel

## What goes on a queue

Anything the user does not need to wait for and that takes more than ~300ms: mail, SMS, push, PDF/Excel generation, image processing, external API sync, webhook delivery, imports, report building.

**A job is not a place to put business logic.** The job is a thin wrapper: it deserializes its input and calls a Service.

> **PROJECT OVERRIDE (Assab).** Read "Action" as **Service** (`Modules/{Module}/app/Services`). Jobs live in `Modules/{Module}/app/Jobs`, listeners in `Modules/{Module}/app/Listeners`. Scheduled commands are registered in `bootstrap/app.php` (or the module provider) on the **Asia/Riyadh** timezone — match the existing entries there. Model IDs are **UUID strings**, not ints, in job constructors.

## Job template

```php
<?php

declare(strict_types=1);

final class SendInvoiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;
    public bool $afterCommit = true;

    /** @return array<int, int> seconds between retries */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function __construct(
        private readonly int $invoiceId,   // ID, not a hydrated model
    ) {}

    public function handle(SendInvoiceAction $action): void
    {
        $action->handle($this->invoiceId);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Invoice delivery failed', [
            'invoice_id' => $this->invoiceId,
            'reason'     => $e?->getMessage(),
        ]);
        // notify ops / mark the record as failed — do NOT re-dispatch here
    }
}
```

## Non-negotiable rules

1. **Idempotent.** Every job runs more than once — after a timeout, a worker restart, or a manual retry. Guard with a status check, a unique constraint, or `upsert()`. Ask: "if this runs twice, does the client get charged twice?"
2. **Pass IDs, not models.** `SerializesModels` re-fetches by key, which is safe but hides staleness; passing an ID makes the re-fetch explicit and keeps payloads small. Never serialize a whole collection or a file's contents.
3. **`afterCommit`.** A job dispatched inside a transaction can start before the commit and read a row that doesn't exist yet. Set `$afterCommit = true` (or dispatch `->afterCommit()`).
4. **Always set `$tries`, `backoff()`, `$timeout`, and `failed()`.** An unbounded retry on a permanently failing job will burn the queue.
5. **Distinguish retryable from permanent failures.** Network/5xx → let it retry. Validation/404/business-rule failure → `$this->fail($e)` immediately, don't waste retries.
6. **Never queue a job that queues itself** without a bounded counter — that's an infinite loop with a bill attached.

## Queue routing

```php
SendInvoiceJob::dispatch($id)->onQueue('emails')->afterCommit();
```

- Separate queues by latency class: `high` (user-facing), `default`, `low` (reports, syncs)
- Workers must be started per queue with explicit priority: `php artisan queue:work --queue=high,default,low`
- **Deploy step:** `php artisan queue:restart` after every deploy — workers hold the old code in memory otherwise
- Driver: `database` is fine to start; move to `redis` + Horizon when throughput or visibility matters

## Uniqueness & rate limits

```php
final class SyncClientJob implements ShouldQueue, ShouldBeUnique
{
    public int $uniqueFor = 300;   // seconds

    public function uniqueId(): string
    {
        return "sync-client-{$this->clientId}";
    }
}
```

- `ShouldBeUnique` needs a real cache lock driver (redis/memcached/database) — it silently does nothing on the `array` driver
- Rate-limited external APIs: `Redis::throttle('provider')->allow(10)->every(60)` inside `handle()`, or `RateLimited` middleware on the job

## Batches

```php
Bus::batch($chunks)
    ->name("import-{$importId}")
    ->allowFailures()
    ->then(fn (Batch $b) => MarkImportComplete::dispatch($importId))
    ->catch(fn (Batch $b, Throwable $e) => MarkImportFailed::dispatch($importId, $e->getMessage()))
    ->onQueue('low')
    ->dispatch();
```

Use batches for imports/exports split into chunks. Track progress via `$batch->progress()` and expose it to the client — never leave a long import with no status endpoint.

## Events & listeners

- Event names are past tense: `InvoiceIssued`, `PaymentReceived`
- Events carry IDs and primitives, not hydrated models
- Listeners that do I/O implement `ShouldQueue` — a sync listener runs inside the request and can break the response
- Never put business rules in a listener that other code depends on. Listeners are for **side effects** (notify, log, sync). If the outcome must happen, call it from the Action explicitly
- Model observers: use for narrow, always-true invariants (slug generation, audit trail). Never for business workflows — they fire on seeders, factories, and bulk scripts too, and they make behavior invisible

## Scheduled commands

```php
// routes/console.php
Schedule::command(SyncExchangeRates::class)
    ->hourly()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground()
    ->onFailure(fn () => Log::error('Exchange rate sync failed'));
```

- `withoutOverlapping()` on anything that can run long
- `onOneServer()` on any multi-server deployment — otherwise every node runs it
- Schedule the **dispatch of a job**, not the heavy work itself — the scheduler process should never be blocked
- Set timezone explicitly (`->timezone('Africa/Cairo')`) when the business day matters

## Testing

```php
Queue::fake();
// ...
Queue::assertPushed(SendInvoiceJob::class, fn ($job) => $job->invoiceId === $invoice->id);
Queue::assertPushedOn('emails', SendInvoiceJob::class);

Bus::fake();  Bus::assertBatched(fn (PendingBatch $b) => $b->jobs->count() === 3);
Event::fake([InvoiceIssued::class]);
```

Test the job's `handle()` directly as a unit test too — faking only proves it was dispatched.

## Checklist

- [ ] Job wraps an Action, holds no business logic
- [ ] Constructor takes IDs/primitives, not models or large payloads
- [ ] Idempotent — running twice is safe
- [ ] `$tries`, `backoff()`, `$timeout`, `failed()` all set
- [ ] `$afterCommit = true` if it reads data written in the same request
- [ ] Correct queue for its latency class
- [ ] Permanent failures call `$this->fail()` instead of retrying
- [ ] Failure is visible: logged, recorded on the model, or alerted
- [ ] Dispatch asserted in a feature test, `handle()` covered by a unit test
- [ ] `queue:restart` documented in the deploy notes

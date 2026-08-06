---
name: laravel-query-performance
description: Query, Eloquent, and indexing rules for Laravel backends. Use before writing any list endpoint, report, export, dashboard aggregate, relation load, or bulk operation, and when investigating slow endpoints, timeouts, high memory usage, or N+1 queries. Triggers on "slow", "timeout", "N+1", "optimize", "report", "export", "dashboard", "large table".
allowed-tools: Read Grep Glob Edit Bash(php artisan db:*) Bash(php artisan tinker *)
---

# Query Performance — Laravel

The single biggest source of production incidents in a Laravel API is a query that was fine with 100 rows and fatal with 100,000. Apply these before writing the query, not after the timeout.

> **PROJECT OVERRIDE (Assab).** Queries live in `Modules/{Module}/app/Repositories` (or a Service). Output classes are **Transformers**, and the `whenLoaded()` rule applies to them exactly as written. `Model::preventLazyLoading()` is **not** currently enabled in this project — do not assume a lazy load will error in dev; check for it by reading the code. List endpoints return `paginatedResponse()` from `BaseController`.

## 1) N+1 — the default failure

```php
// ❌ 1 + N queries
$invoices = Invoice::all();
foreach ($invoices as $invoice) { echo $invoice->client->name; }

// ✅ 2 queries, only the columns needed
$invoices = Invoice::query()
    ->select(['id', 'client_id', 'total'])
    ->with('client:id,name')
    ->paginate(15);
```

- `Model::preventLazyLoading(! app()->isProduction())` must be enabled in `DomainServiceProvider::boot()` — a lazy load becomes a hard error in dev/test
- Resources MUST use `whenLoaded('relation')`. A Resource that calls `$this->relation` directly will N+1 across the whole page
- `withCount('lines')` instead of `$model->lines->count()`
- Nested: `with('client.company:id,name')`. Constrained: `with(['lines' => fn ($q) => $q->where('active', true)])`
- Polymorphic/varied relations: `with('commentable')` still N+1s per type — use `morphWith`

## 2) Never load an unbounded set

| Need | Use |
|---|---|
| API list | `paginate()` — or `simplePaginate()` when the total count is expensive |
| Infinite scroll / huge offsets | `cursorPaginate()` — offset pagination degrades badly past ~10k rows |
| Bulk processing | `chunkById(500, ...)` — **not** `chunk()`, which skips rows when the loop mutates the filter column |
| Streaming a large export | `lazyById()` / `cursor()` + a streamed response |
| Existence check | `exists()` — never `count() > 0`, never `->get()->isNotEmpty()` |
| Counting | `count()` on the query — never `->get()->count()` |

## 3) Aggregates and reports

- Aggregate in SQL (`selectRaw('sum(total) as total')`, `groupBy`) — never in PHP over a hydrated collection
- Use `toBase()` / `DB::table()` for read-only aggregates — skipping Eloquent hydration is often a 5–10× win
- Anything heavier than ~1s: precompute into a summary table via a scheduled command, or cache with an explicit TTL and a clear invalidation event
- `whereHas()` on a large table generates a correlated subquery — prefer a join, or a denormalized flag column, when the relation table is big

## 4) Indexing rules

- Every foreign key gets an index (`constrained()` does **not** always add one on all drivers — verify)
- Every column used in `where`, `orderBy`, or `join` on a growing table gets an index
- Composite index order = **equality columns first, range/sort column last**: `['tenant_id', 'status', 'created_at']`
- A composite index serves left-to-right prefixes: `[a, b, c]` covers `a`, `a+b`, `a+b+c` — not `b` alone
- Do NOT index low-cardinality columns alone (a boolean flag) — combine it with the tenant/owner column
- Adding an index to a large live table locks it on some engines — see the `@migration-reviewer` agent before shipping

## 5) Writes

- Bulk insert: `Model::insert($rows)` (fast, skips events/timestamps — add `created_at` manually) or `upsert()` for idempotent syncs
- Bulk update: one `->update([...])` query, not a loop of `save()`
- Counters: `->increment('views')` (atomic) — never read-modify-write
- Money/stock under concurrency: `lockForUpdate()` inside a transaction, or a unique constraint that makes the double-write impossible

## 6) Caching — only after the query is already correct

- Cache the **result**, keyed by every input that changes it (tenant, filters, page, locale)
- Always set a TTL. Cache with no invalidation path is a bug waiting for a support ticket
- Invalidate on the domain event that changes the data, not on a timer, when correctness matters
- Never cache per-user data under a shared key

## 7) Diagnosing a slow endpoint

```php
// Log every query with its bindings and time (dev only)
DB::listen(fn ($q) => logger()->debug($q->sql, ['ms' => $q->time]));
```

```bash
php artisan db:monitor              # connection/pool health
php artisan tinker                  # reproduce the query in isolation
```

- Read the actual plan: `DB::select('EXPLAIN ' . $query->toSql(), $query->getBindings())`
- Count queries in a test: `DB::enableQueryLog()` then assert `count(DB::getQueryLog())` — lock the count in a regression test
- Laravel Telescope/Debugbar in local only — never enabled in production

## Checklist before shipping a query

- [ ] All relations used in the output are eager loaded
- [ ] Only the needed columns are selected
- [ ] The result set is bounded (paginated or chunked)
- [ ] Filter/sort columns are indexed, composite order correct
- [ ] Aggregation happens in SQL, not PHP
- [ ] Query count is stable regardless of the number of rows returned
- [ ] Tested against a realistic row count, not 10 seed rows

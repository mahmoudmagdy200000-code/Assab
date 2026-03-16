# Quickstart: Modules N+1 Query Production Review

## Goal

Run a **safe, read-only review** of Laravel modules to identify N+1 queries, slow queries, locking/deadlock risks, connection pool exhaustion risks, and reliability pattern gaps **without changing production responses, business rules, or database schema**.

## 1. Select Environment

1. Prefer **staging** or a **read-only replica** of production.
2. If you must observe real production traffic, rely only on:
   - Existing query logs / APM,
   - Existing Laravel telemetry (e.g., telescope/debugbar in safe mode),
   - Low-impact sampling, without adding new heavy logging.

## 2. Identify High-Risk Flows

1. From the spec, list modules and flows to review first:
   - Expenses (recent expenses, reports).
   - Orders and Recurring Orders.
   - Inventory sessions and monthly inventory.
2. For each flow, note:
   - Main controller actions and services.
   - Key Eloquent relationships and queries.

## 3. Detect N+1 and Slow Queries

1. Enable query inspection on the chosen environment (e.g., debugbar/telescope on staging).
2. For each high-risk flow:
   - Trigger the flow (e.g., open list pages, run reports).
   - Capture:
     - Query count per request.
     - Queries taking >200ms or returning large row counts.
3. Cross-check code:
   - Look for loops with relationship access (`$items->each->relation`, nested eager loads).
   - Confirm that required relationships are eager-loaded and selective (`with()`, selective columns).
4. Record each issue as a `Finding` in the report (see `contracts/review-report.md`).

## 4. Assess Locks, Deadlocks, and Connection Pool Risks

1. Review:
   - Transaction usage (`DB::transaction`, nested transactions).
   - Long-running queries and report generation flows.
   - Any use of `SELECT ... FOR UPDATE` or explicit locking.
2. Check database monitoring/slow query logs for:
   - Deadlock errors.
   - Connection pool exhaustion or timeouts.
3. Document risks as `Finding` entries with severity and recommended follow-up features.

## 5. Evaluate Reliability Patterns

1. **Idempotency & Queues**:
   - Inspect jobs handling money, inventory, or state transitions.
   - Verify idempotent behavior under retries where required.
2. **Eventual Consistency & Caching**:
   - Identify flows relying on delayed updates or caches.
   - Note where stale data might appear or where invalidation is weak.
3. **Isolation Levels & Race Conditions**:
   - Review default DB isolation level and critical transactions.
   - Note potential race conditions (concurrent updates, missing constraints).

## 6. Write the Report

1. Use `contracts/review-report.md` as the structure.
2. Group findings by module and pattern category.
3. Ensure each recommended action is framed as a **future feature**, not a change to this branch.
4. Explicitly state at the top of the report:
   - That this review did **not** modify any production responses, business rules, or schema.

## 7. Next Steps

- For each critical/high-severity `Finding`, create a follow-up feature spec and plan that:
  - Implements the fix.
  - Adds appropriate unit and feature tests.
  - Continues to respect Clean Architecture and performance constraints.


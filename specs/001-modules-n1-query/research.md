# Research & Decisions: Modules N+1 Query Production Review

## Overview

This document captures the key decisions and rationale for how we will review N+1 queries, slow queries, locks/deadlocks, connection pool exhaustion risks, and reliability patterns (idempotency, eventual consistency, queues & messaging, caching, isolation levels) **without changing production behavior or responses**.

There were no explicit `[NEEDS CLARIFICATION]` markers in the spec. All open questions have reasonable defaults based on the Assab constitution and existing Laravel 11 stack.

## Decisions

### 1. Where to run profiling and analysis

- **Decision**: Prefer staging and read-only production replicas for heavy query profiling; use production only for lightweight logging/metrics already enabled.
- **Rationale**: This minimizes risk to live users while still exposing realistic data volumes and query patterns.
- **Alternatives considered**:
  - Run all profiling directly on production with additional logging → Rejected due to potential performance impact.
  - Use synthetic/local datasets only → Rejected because it may miss production-specific N+1 and locking patterns driven by real data.

### 2. N+1 and slow query detection approach

- **Decision**: Use Laravel debug tooling (e.g., telescope/debugbar/query logs) on non-production environments and targeted logging in code review (Eloquent relationship patterns) to identify N+1 and slow queries.
- **Rationale**: Combines runtime evidence with static analysis of Eloquent usage, aligning with the constitution’s ban on N+1 queries without requiring intrusive production changes.
- **Alternatives considered**:
  - Add new profiling middleware or wrappers in production → Rejected for this feature because it would change runtime behavior.
  - Rely solely on code review without any runtime insight → Rejected because it risks missing hidden slow queries.

### 3. Evaluating locks, deadlocks, and connection pool risks

- **Decision**: Review transaction usage, long-running queries, and lock-prone patterns in code, and correlate with DB monitoring/slow query logs where available.
- **Rationale**: This provides a realistic view of deadlock and pool exhaustion risks while staying within read-only analysis and existing monitoring.
- **Alternatives considered**:
  - Introduce new database-level instrumentation or schema changes → Rejected because this feature must not change schema or production behavior.
  - Ignore DB statistics and rely only on code → Rejected as it may miss real contention hotspots.

### 4. Reliability patterns (idempotency, consistency, queues, caching, isolation)

- **Decision**: Systematically review:
  - Job handlers and queue usage for idempotency and retry safety.
  - Places where eventual consistency is intentionally used (e.g., delayed updates, projections).
  - Caching layers for lifetime and invalidation patterns.
  - Database isolation level configuration and transaction scope in critical flows.
- **Rationale**: This aligns directly with the spec and constitution, giving a clear map of current guarantees and gaps without modifying code.
- **Alternatives considered**:
  - Introduce new reliability patterns in this feature → Rejected to honor the “analysis-only, no behavior changes” constraint.
  - Limit the review to a single module → Rejected; the requested scope is cross-module.

### 5. Deliverable format

- **Decision**: Produce a structured written report (under this feature directory) that groups findings by module, severity, and pattern category (performance, locking, reliability, etc.), along with recommended follow-up features for actual fixes.
- **Rationale**: Keeps this feature purely documentation-focused while giving a clear roadmap for future implementation work that will include tests and code changes.
- **Alternatives considered**:
  - Implement fixes immediately inside this feature → Rejected due to explicit requirement not to change production responses or schema.
  - Produce only ad-hoc notes → Rejected because they are hard to track and act on later.


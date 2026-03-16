# Data Model: Modules N+1 Query Production Review

## Overview

This feature introduces **no new runtime data structures or database schema**.  
The data model below exists only for structuring the analysis and reports.

## Entities

### 1. ModuleReviewTarget

- **Description**: Logical representation of a Laravel module or bounded context under review.
- **Key Fields**:
  - `name` — string; module name (e.g., `Expense`, `RecurringOrder`).
  - `entrypoints` — list of controller/service methods representing main flows.
  - `tables` — list of primary database tables used in the module.
  - `relationships` — summary of important Eloquent relationships involved.

### 2. Finding

- **Description**: A single identified issue or observation from the review.
- **Key Fields**:
  - `id` — identifier within the report.
  - `module` — reference to `ModuleReviewTarget`.
  - `type` — enum; e.g., `N+1_QUERY`, `SLOW_QUERY`, `MISSING_INDEX`, `LOCK_RISK`, `DEADLOCK_RISK`, `POOL_EXHAUSTION_RISK`, `NON_IDEMPOTENT_JOB`, `CACHING_GAP`, `ISOLATION_RISK`, `RACE_CONDITION`.
  - `severity` — enum; e.g., `CRITICAL`, `HIGH`, `MEDIUM`, `LOW`.
  - `location` — string; file path and line/method reference.
  - `description` — text; what was observed.
  - `evidence` — text; query samples, logs, stack traces, or reasoning.
  - `recommended_actions` — text; suggested follow-up changes (to be implemented in future features).

### 3. PatternCategory

- **Description**: Category used to group findings by concern.
- **Key Fields**:
  - `name` — enum; `PERFORMANCE`, `LOCKING`, `CONNECTION_MANAGEMENT`, `RELIABILITY`, `CACHING`, `CONSISTENCY`.
  - `description` — text; explains what the category covers.

### 4. ReviewSession

- **Description**: A logical grouping of findings produced in one review pass.
- **Key Fields**:
  - `id` — identifier within documentation.
  - `date` — date/time of the session.
  - `targets` — list of `ModuleReviewTarget` instances covered.
  - `summary` — text; high-level overview of main findings.

## Relationships

- `ModuleReviewTarget` **has many** `Finding`.
- `Finding` **belongs to** exactly one `ModuleReviewTarget`.
- `Finding` **belongs to** one or more `PatternCategory` values (conceptually many-to-many, represented via tagging in documentation).
- `ReviewSession` **has many** `ModuleReviewTarget` and **has many** `Finding`.


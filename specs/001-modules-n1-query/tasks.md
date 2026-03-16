# Tasks: Modules N+1 Query Production Review

**Input**: Design documents from `specs/001-modules-n1-query/`  
**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/`, `quickstart.md`

**Tests**: This feature is analysis- and documentation-only. No new automated tests are required here, but follow-up features that implement fixes MUST add appropriate unit and feature tests.

**Organization**: Tasks are grouped by user story so each review slice can be executed and validated independently.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- Descriptions include exact file paths where applicable

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Prepare safe, non-invasive environments and documentation scaffolding for the review.

- [ ] T001 Confirm staging or read-only replica environment availability per `specs/001-modules-n1-query/research.md`
- [ ] T002 Document which Laravel modules are in review scope in `specs/001-modules-n1-query/research.md`
- [ ] T003 [P] Ensure access to existing DB monitoring/slow query logs and APM tools (no new production config changes)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core prerequisites that MUST be complete before any user story review work starts.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [ ] T004 Identify high-risk flows (expenses, orders, recurring orders, inventory) in `app/Modules/*` and list them in `specs/001-modules-n1-query/research.md`
- [ ] T005 [P] Outline the structure of the final review report in `specs/001-modules-n1-query/contracts/review-report.md` (fill in any missing sections)
- [ ] T006 Establish severity and pattern-category labels to use for `Finding` entries in `specs/001-modules-n1-query/data-model.md`

**Checkpoint**: Foundation ready — user story implementation (review work) can now begin.

---

## Phase 3: User Story 1 - Production N+1 & Slow Query Review (Priority: P1) 🎯 MVP

**Goal**: Systematically detect and document N+1 queries, slow queries, and missing or suboptimal indexes across Laravel modules without changing any production responses.

**Independent Test**: By following only the tasks in this phase (after Setup/Foundational), you can generate a report section listing all detected N+1 and slow query issues with module, location, and evidence, while confirming that no runtime behavior or schema changed.

### Implementation for User Story 1

- [ ] T007 [P] [US1] From `app/Modules/*`, list database-heavy endpoints and pages to profile in `specs/001-modules-n1-query/research.md`
- [ ] T008 [P] [US1] Using `specs/001-modules-n1-query/quickstart.md`, run N+1 and slow query profiling on staging or a replica and capture query metrics
- [ ] T009 [US1] For each high-risk flow, review Eloquent usage in `app/Modules/*` and note potential N+1 patterns (loops, missing `with()` calls) in `specs/001-modules-n1-query/research.md`
- [ ] T010 [US1] Create initial `Finding` entries for N+1 and slow queries in the review report file under `specs/001-modules-n1-query/contracts/` following `review-report.md`
- [ ] T011 [US1] Classify each Finding by severity and pattern category (PERFORMANCE) using the schema in `specs/001-modules-n1-query/data-model.md`
- [ ] T012 [US1] Add recommended follow-up features (without code changes) for top-severity N+1 and slow query findings to the report’s roadmap section
- [ ] T013 [US1] Add an explicit statement in the report that no controllers, services, repositories, or responses were changed while executing User Story 1

**Checkpoint**: User Story 1 complete — you have a production-safe N+1 and slow query assessment and can stop here as an MVP.

---

## Phase 4: User Story 2 - Concurrency, Locks, and Deadlocks Assessment (Priority: P2)

**Goal**: Analyze database locks, deadlock risks, and connection pool usage patterns, documenting risk areas without introducing any code or schema changes.

**Independent Test**: By executing only this phase (after Setup/Foundational), you will produce a documented list of locking and connection risks with examples and recommendations, while application behavior and responses remain unchanged.

### Implementation for User Story 2

- [ ] T014 [P] [US2] Identify key transactional flows (e.g., financial updates, inventory adjustments) in `app/Modules/*` and list them in `specs/001-modules-n1-query/research.md`
- [ ] T015 [US2] Review usage of `DB::transaction` and any explicit locking (e.g., `SELECT ... FOR UPDATE`) in `app/Modules/*` and summarize patterns in `research.md`
- [ ] T016 [P] [US2] Correlate code-level findings with database monitoring/slow query logs to identify actual or potential deadlocks and long-running transactions
- [ ] T017 [US2] Add `Finding` entries for lock risks, deadlock risks, and long transactions in the review report under `specs/001-modules-n1-query/contracts/` using the `LOCK_RISK`, `DEADLOCK_RISK`, or related types
- [ ] T018 [US2] Document any evidence of connection pool exhaustion or timeouts from monitoring tools as `POOL_EXHAUSTION_RISK` findings in the report
- [ ] T019 [US2] Propose follow-up feature ideas to mitigate the highest-risk concurrency issues, recorded in the report’s roadmap section (no code changes here)
- [ ] T020 [US2] Reaffirm in the report that all observations and recommendations came from read-only analysis and existing monitoring, with no runtime behavior modifications

**Checkpoint**: User Stories 1 and 2 complete — performance and concurrency risks are documented and independently reviewable.

---

## Phase 5: User Story 3 - Reliability Patterns Review (Idempotency, Consistency, Queues, Caching) (Priority: P3)

**Goal**: Review how idempotency, eventual consistency, queues & messaging, caching, and isolation levels are currently applied, and document reliability and data-consistency guarantees without impacting live traffic.

**Independent Test**: After this phase, you have a clear map of current reliability patterns and their gaps, with all changes confined to documentation under `specs/001-modules-n1-query/`.

### Implementation for User Story 3

- [ ] T021 [P] [US3] Inventory critical jobs and queues (e.g., money, inventory, state transitions) in `app/Modules/*` and list them in `specs/001-modules-n1-query/research.md`
- [ ] T022 [US3] Review job handlers and retry logic for idempotent behavior, documenting safe vs unsafe operations in `research.md`
- [ ] T023 [P] [US3] Analyze caching usage (e.g., cache keys, TTLs, invalidation) in relevant modules and record potential `CACHING_GAP` findings in the review report
- [ ] T024 [US3] Review database isolation level configuration and transaction scopes in critical flows, documenting `ISOLATION_RISK` and potential `RACE_CONDITION` findings in the report
- [ ] T025 [US3] Summarize current eventual consistency patterns (where writes and reads are intentionally decoupled) in the report, noting benefits and risks
- [ ] T026 [US3] Add a consolidated “Reliability & Consistency” section to the review report under `specs/001-modules-n1-query/contracts/`, grouping relevant `Finding` entries
- [ ] T027 [US3] Extend the roadmap in the report with follow-up features to improve idempotency, caching, and isolation where risks are highest

**Checkpoint**: All three user stories complete — the system’s performance, concurrency, and reliability posture is documented without any production-impacting change.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Improve clarity and usability of the documentation and make it easy to act on findings.

- [ ] T028 [P] Clean up and organize the final review report under `specs/001-modules-n1-query/contracts/` for consumption by engineering and leadership
- [ ] T029 [P] Update `specs/001-modules-n1-query/quickstart.md` with any lessons learned or clarified steps from this execution
- [ ] T030 Ensure `specs/001-modules-n1-query/spec.md`, `plan.md`, and `tasks.md` consistently emphasize that no production responses, business rules, or schema were changed in this feature

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — can start immediately.
- **Foundational (Phase 2)**: Depends on Setup completion — BLOCKS all user stories.
- **User Stories (Phases 3–5)**: All depend on Foundational phase completion.
  - User stories can proceed in priority order (P1 → P2 → P3) and, where capacity allows, partially in parallel thanks to [P] tasks.
- **Polish (Phase 6)**: Depends on completion of all desired user stories.

### User Story Dependencies

- **User Story 1 (P1)**: Can start after Foundational (Phase 2) — independent of other stories.
- **User Story 2 (P2)**: Can start after Foundational (Phase 2) — logically benefits from context in User Story 1 but is independently executable.
- **User Story 3 (P3)**: Can start after Foundational (Phase 2) — can be run in parallel with US2, using findings from US1 where helpful.

### Parallel Opportunities

- All tasks marked [P] can be run in parallel when different people or time slots are available.
- Within each user story, environment preparation and evidence gathering tasks can proceed concurrently as long as they do not compete for the same DB resources.
- Different user stories (US2, US3) can be worked on in parallel once Foundational is complete, provided coordination on shared monitoring tools.

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup.  
2. Complete Phase 2: Foundational.  
3. Complete Phase 3: User Story 1.  
4. **STOP and VALIDATE**: Ensure the N+1/slow query report section is complete and confirm that no production behavior changed.

### Incremental Delivery

1. Execute MVP (US1) and share results with stakeholders.  
2. Add User Story 2 to document concurrency and locking risks.  
3. Add User Story 3 to capture reliability and consistency patterns.  
4. Use Phase 6 to polish documentation and extract follow-up feature ideas for actual code changes (with tests) in future branches.


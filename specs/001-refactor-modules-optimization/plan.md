# Implementation Plan: Refactor Modules for Cleaner and Optimized Code

**Branch**: `001-refactor-modules-optimization` | **Date**: 2026-03-08 | **Spec**: [spec.md](./spec.md)  
**Input**: Feature specification from `/specs/001-refactor-modules-optimization/spec.md`

## Summary

Refactor the Expense, Shift, and Purchase modules to improve code quality (separation of concerns, reduced duplication, consistent patterns) and performance (e.g. fewer queries, same or better response time) while preserving **identical API responses** for all existing endpoints. No change to response body, status codes, or error format is permitted (production constraint). Work is limited to internal structure and optimization within these three modules.

## Technical Context

**Language/Version**: PHP 8.x  
**Primary Dependencies**: Laravel, modular structure under `app/Modules/`  
**Storage**: Existing database; migrations/schema unchanged for this feature  
**Testing**: PHPUnit (unit + feature tests); existing tests must pass unchanged  
**Target Platform**: Web API (Linux/server)  
**Project Type**: Web service (Laravel API)  
**Performance Goals**: API ≤500ms for 95% of requests; reports ≤30s; export ≤60s for 10k records; no regression  
**Constraints**: Zero change to API response (body, status, error format); same side effects for same inputs  
**Scale/Scope**: Three modules (Expense, Shift, Purchase); all their API routes and backing logic in scope for refactor only

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Requirement | Refactor Compliance |
|-----------|--------------|----------------------|
| **I. Code Quality** | Controller → Service → Repository → Model; business logic in services only; SOLID; methods ≤20 lines; PHPDoc; eager loading, no N+1 | Refactor moves logic from controllers into services/repositories where missing; preserves or improves structure. |
| **II. Testing Standards** | Unit tests for services; feature tests for API; ≥70% coverage; tests verify behavior | Existing tests remain green; refactor adds/updates tests only to reflect same behavior. |
| **III. User Experience Consistency** | Consistent response format and HTTP status; predictable error structure; Arabic/English support | **Immutable**: No change to any response or error format (explicit spec constraint). |
| **IV. Performance Requirements** | ≤500ms p95; reports ≤30s; export ≤60s for 10k; eager loading, no N+1; indexes | Refactor must not regress and should improve query count/response time where possible. |
| **V. Security First** | Form Requests; auth on every endpoint; Policies; parameterized queries; rate limiting | No change to validation or auth; refactor may move validation to Form Requests if not already. |

**Gate result**: PASS. Refactor aligns with constitution; response immutability satisfies III and avoids breaking clients.

## Project Structure

### Documentation (this feature)

```text
specs/001-refactor-modules-optimization/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output (existing entities; no schema change)
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output (API contract preservation)
└── tasks.md             # Phase 2 output (/speckit.tasks – not created by /speckit.plan)
```

### Source Code (repository root)

```text
Modules/
├── Expense/
│   ├── app/Http/Controllers/   # Thin controllers; logic → Services
│   ├── routes/api.php          # Branch-manager expense routes
│   └── ... (services, models as introduced by refactor)
├── Shift/
│   ├── app/Http/Controllers/   # Thin controllers; logic → Services
│   ├── routes/api.php          # Branch-manager shift routes
│   └── ...
└── Purchase/
    ├── app/Http/Controllers/   # Thin controllers; logic → Services
    ├── routes/api.php          # v1/purchase routes
    └── ...

tests/   # Unit + feature tests; behavior unchanged after refactor
```

**Structure Decision**: Laravel modular layout. Refactor scope is limited to `Modules/Expense`, `Modules/Shift`, and `Modules/Purchase`. New or moved code (e.g. Services, Repositories) lives within each module; shared helpers only where they do not change responses of these modules.

## Complexity Tracking

> No constitution violations. This section is empty.

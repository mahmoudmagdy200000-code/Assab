# Research: Refactor Modules for Cleaner and Optimized Code

**Feature**: 001-refactor-modules-optimization  
**Phase**: 0 (Outline & Research)

## 1. Refactoring Toward Controller → Service → Repository

**Decision**: Apply the constitution’s layered pattern (Controller → Service → Repository → Model) inside Expense, Shift, and Purchase. Controllers only handle HTTP (request/response); business logic moves into dedicated Service classes; data access goes into Repositories where it improves clarity or reuse.

**Rationale**: Aligns with project constitution (Code Quality). Reduces duplication, improves testability (services unit-testable without HTTP), and keeps controllers thin and readable.

**Alternatives considered**:
- **Keep logic in controllers**: Rejected; violates constitution and makes behavior harder to test and reuse.
- **Action classes per operation**: Viable for very large modules; deferred until after basic Service/Repository extraction so we don’t over-split early.

---

## 2. Preserving API Response Contract

**Decision**: Treat existing API responses as the immutable contract. Verify refactors with (a) existing feature/HTTP tests, and (b) optional response snapshot or schema checks for critical endpoints. No intentional change to response keys, types, order, or status codes.

**Rationale**: Spec and user requirement explicitly forbid any change in production responses. Snapshot or schema tests give a safety net when refactoring serialization or resource classes.

**Alternatives considered**:
- **No extra verification**: Rely only on current tests; acceptable if coverage is strong; add snapshots where coverage is weak or endpoints are critical.
- **Full snapshot of every endpoint**: High maintenance; prefer for a representative set or high-risk endpoints.

---

## 3. N+1 and Query Optimization

**Decision**: Use eager loading (`with()`) for relationships used in responses; select only needed columns where it doesn’t change response shape; use `chunk()` for large iterations; add indexes only where they don’t require schema/migration changes that could affect behavior. Profile or count queries on hot paths to confirm no N+1 and same or better performance.

**Rationale**: Constitution forbids N+1 and requires performance targets. Refactor is an opportunity to fix N+1 and reduce query count without changing output.

**Alternatives considered**:
- **Lazy loading everywhere**: Rejected; causes N+1.
- **Aggressive select() that might change attributes on models**: Rejected; could change JSON output; only use selective columns where response remains identical.

---

## 4. Shared Code and Cross-Module Impact

**Decision**: Limit refactor to code that affects only Expense, Shift, and Purchase. If extracting shared helpers or base classes, ensure they don’t change response format or behavior for any other module. Prefer module-local services/repositories; introduce shared code only when duplication is clear and behavior is identical.

**Rationale**: Spec FR-007: changes must not alter behavior or responses of other modules. Keeping scope local minimizes risk.

**Alternatives considered**:
- **Global refactor of all modules**: Out of scope and riskier.
- **Shared “Core” service layer**: Acceptable only for logic that does not touch response shape; apply cautiously.

---

## 5. Error and Validation Response Format

**Decision**: Leave all error response structures and validation messages unchanged. If moving validation into Form Requests, preserve the same rules and the same message keys/values so API consumers see identical responses.

**Rationale**: Spec FR-002 and edge cases require unchanged error format and validation messages.

**Alternatives considered**:
- **Improving error messages**: Out of scope for this refactor; would change response.
- **Standardizing on a different format**: Rejected; would break production clients.

---

**T014 (centralization)**: No new cross-module shared code was introduced. Common behavior uses existing app-level helpers (e.g. `BaseController`). Expense, Shift, and Purchase keep module-local services/repositories so responses are not altered (FR-007).

## Summary

| Topic | Decision |
|-------|----------|
| Architecture | Controller → Service → Repository within each module; controllers thin. |
| API contract | Immutable; verify with existing tests + optional snapshots/schemas for critical endpoints. |
| Queries | Eager loading, selective columns only when response unchanged; no N+1; chunk large work. |
| Scope | Expense, Shift, Purchase only; shared code only when it doesn’t change other modules’ responses. |
| Errors/validation | No change to message text or structure; Form Requests may be introduced but output must stay the same. |

All Technical Context items were already defined (no NEEDS CLARIFICATION). This research documents how refactor and optimization will be applied without changing any API response.

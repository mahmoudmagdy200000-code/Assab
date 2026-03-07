<!--
Sync Impact Report
==================
Version change: 1.0.0 → 1.1.0
Modified principles:
  - I: Clean Architecture → Code Quality (merged observability/maintainability; expanded quality gates)
  - II: API & Performance Standards → Performance Requirements (explicit numeric targets)
  - IV: Testing Discipline → Testing Standards (expanded standards and coverage rules)
  - V: Observability & Maintainability → merged into I (Code Quality)
Added sections: III. User Experience Consistency (new principle)
Removed sections: Standalone "Observability & Maintainability" (content merged into Code Quality)
Templates:
  - .specify/templates/plan-template.md: ✅ No change required (Constitution Check populated from this file at plan time)
  - .specify/templates/spec-template.md: ✅ No change required
  - .specify/templates/tasks-template.md: ✅ No change required
  - .cursor/commands/*.md: ✅ No outdated references
Follow-up TODOs: None
-->

# Assab (Al-Zukhruf Electronic Consultant Office) Constitution

## Core Principles

### I. Code Quality (NON-NEGOTIABLE)

All features MUST follow the layered pattern: **Controller → Service → Repository → Model**, with DTO/Form Request for input. Business logic MUST live in the Service layer only; controllers handle HTTP only. SOLID principles MUST be applied: single responsibility per class, dependency injection, interfaces for abstractions. Methods MUST stay under 20 lines; use early returns and guard clauses instead of deep nesting. Naming MUST follow conventions: singular model/controller names, verbs for methods, descriptive English identifiers (no single-letter or vague names). PHPDoc MUST document all public methods; comments explain "why" where non-obvious. Structured logging with context MUST be used for material operations. Database access MUST use eager loading, selective columns, and chunking for large datasets; N+1 queries are forbidden. No business logic in controllers or views.

**Rationale**: Code quality ensures maintainability, readability, and long-term scalability; every change must meet these gates.

### II. Testing Standards

Unit tests MUST be written for services; feature tests MUST cover API endpoints. Minimum 70% code coverage MUST be maintained. Tests MUST verify behavior and outcomes, not merely presence of code. New library or contract changes MUST have integration/contract tests where applicable. Tests MUST be independently runnable and deterministic. Test organization MUST align with the implementation plan (unit, integration, contract as defined per feature).

**Rationale**: Consistent testing standards reduce regressions and give confidence for refactors and deployments.

### III. User Experience Consistency

APIs MUST use a consistent response format and correct HTTP status codes across all endpoints. Error responses MUST be predictable in structure and message style. User-facing text and APIs MUST support Arabic and English with proper RTL/LTR handling where applicable. Loading states, validation messages, and feedback MUST follow project patterns so users get a consistent experience across modules. New features MUST not introduce one-off response shapes or locale handling that diverges from existing conventions.

**Rationale**: Consistent UX and API behavior reduce integration cost and user confusion in a bilingual, multi-module product.

### IV. Performance Requirements

API response time MUST be ≤500ms for 95% of requests. Report generation MUST complete within 30 seconds for complex financial reports. Data export MUST complete within 60 seconds for 10,000 records. Real-time notifications MUST be delivered within 2 seconds. Pagination, compression, and HTTP caching MUST be used where appropriate. Heavy operations MUST be offloaded to queues (e.g. Redis/Horizon). Database access MUST use eager loading, selective columns, and chunking; N+1 queries are forbidden. Indexes MUST be added for frequently queried columns.

**Rationale**: Explicit performance targets ensure the system remains responsive and scalable under load.

### V. Security First

Input validation MUST be enforced via Form Requests (not in controllers). Authentication and authorization MUST be applied on every endpoint; use Policies for authorization. Parameterized queries only; mass assignment protection and rate limiting on APIs MUST be enabled. XSS/CSRF protections MUST remain enabled.

**Rationale**: Security is a stated project priority; every endpoint must be validated and authorized.

## Technology & Standards

- **Stack**: Laravel (PHP), modular structure under `app/Modules/`.
- **Database**: Migrations for schema; indexes on hot columns; no raw SQL without parameterization; soft deletes where applicable.
- **Localization**: Arabic/English support with proper RTL/LTR handling where applicable.
- **Code style**: PSR and Laravel best practices; meaningful English names; controllers thin, logic in services.

## Development Workflow

- New features MUST align with this constitution before Phase 0 research and again after Phase 1 design (Constitution Check in implementation plans).
- Code reviews MUST verify compliance with principles; complexity MUST be justified.
- Use `.cursor/rules` and project README/docs for runtime development guidance.

## Governance

This constitution supersedes ad-hoc practices for this repository. Amendments require documentation, rationale, and version bump. All PRs and reviews MUST verify compliance with these principles. Violations MUST be flagged; exceptions require explicit justification (e.g. in plan Complexity Tracking). Version changes follow semantic versioning: MAJOR for backward-incompatible principle removals/redefinitions, MINOR for new principles or material expansion, PATCH for clarifications and non-semantic fixes.

**Version**: 1.1.0 | **Ratified**: 2025-03-07 | **Last Amended**: 2025-03-07

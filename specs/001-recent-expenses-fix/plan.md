# Implementation Plan: Recent Expenses Endpoint Returns Data When User Has Expenses

**Branch**: `001-recent-expenses-fix` | **Date**: 2025-03-07 | **Spec**: [spec.md](./spec.md)  
**Input**: Feature specification from `specs/001-recent-expenses-fix/spec.md`

## Summary

The recent expenses endpoint currently excludes expenses with status `pending`, so branch managers with only pending expenses see an empty list. The fix is to return the most recent expenses for the authenticated branch manager regardless of status (up to a limit of 10), using the same response shape as the main expenses list. Implementation will remove the status filter from the recent-query path and, for constitution compliance, move the query behind a repository (or equivalent) so the controller stays thin.

## Technical Context

**Language/Version**: PHP 8.x (Laravel)  
**Primary Dependencies**: Laravel, Expense module (Modules/Expense)  
**Storage**: Relational DB (Eloquent, Expense model)  
**Testing**: PHPUnit; feature tests for API endpoints  
**Target Platform**: Web API (Laravel backend)  
**Project Type**: Web application (modular Laravel)  
**Performance Goals**: API ≤500ms p95 (constitution); recent list is a single small query  
**Constraints**: Same auth as main expenses list; response shape matches main list (ExpenseResource)  
**Scale/Scope**: Single endpoint change; optional new repository for Expense module

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|-----------|--------|--------|
| I. Code Quality | ✓ | Query for recent expenses will live in repository/service; controller delegates and returns response only. |
| II. Testing Standards | ✓ | Feature test for GET recent endpoint (with/without expenses, status mix). |
| III. User Experience Consistency | ✓ | Response format unchanged (ExpenseResource); same success/message/data shape. |
| IV. Performance Requirements | ✓ | Single query, limit 10, eager loading already used; no new N+1. |
| V. Security First | ✓ | No new endpoint; existing auth/middleware on route unchanged. |

## Project Structure

### Documentation (this feature)

```text
specs/001-recent-expenses-fix/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/          # Phase 1 output (API contract for GET recent)
└── tasks.md             # Phase 2 output (/speckit.tasks)
```

### Source Code (repository root)

```text
Modules/Expense/
├── app/
│   ├── Http/Controllers/ExpenseController.php   # recent() delegates to repository/service
│   ├── Repositories/                            # NEW: optional for this feature
│   │   └── ExpenseRepository.php                # getRecentForBranchManager($id, $limit)
│   ├── Models/Expense.php
│   ├── Transformers/ExpenseResource.php
│   └── ...
├── routes/api.php
└── ...
```

**Structure Decision**: Laravel modular layout. Expense module already has Controllers, Models, Transformers. No repository exists yet; plan adds an optional ExpenseRepository for constitution alignment (controller thin, query in one place). Alternative: minimal fix by only removing the status filter in the controller if the team defers repository introduction.

## Complexity Tracking

No constitution violations. Optional repository adds one new class; complexity is low.

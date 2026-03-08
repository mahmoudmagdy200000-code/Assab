# Research: Recent Expenses Endpoint Fix

## Decision: Include All Statuses in Recent Expenses

**Decision**: The recent expenses endpoint MUST return the most recent expenses for the branch manager regardless of status (pending, approved, rejected, etc.). The current filter `where('status', '!=', 'pending')` is removed so that dashboards and widgets show recent activity even when all expenses are still pending.

**Rationale**: The spec and user report state that when the user has expenses, the recent list must be non-empty when appropriate. Excluding pending expenses contradicts that and causes empty lists for new or in-progress workflows.

**Alternatives considered**:
- Keep "recent" as "recently approved/settled" only: Rejected because it breaks the stated requirement and user expectation (see spec FR-002, acceptance scenario 3).
- Add an optional query parameter to filter by status: Deferred; spec assumes default behavior includes all statuses with no configurable filter for this feature.

## Decision: Query Location (Constitution Compliance)

**Decision**: Prefer moving the recent-expenses query into a dedicated data-access layer (e.g. `ExpenseRepository::getRecentForBranchManager`) so the controller remains thin and constitution Code Quality (I) is satisfied. If the team prefers minimal change, the fix can be done by removing the status filter directly in the controller with a follow-up task to introduce the repository later.

**Rationale**: Constitution requires business logic in the service layer and controllers that only handle HTTP. Reading "recent N expenses" is a clear query responsibility; other modules (Purchase, Inventory, Shift) already use repositories.

**Alternatives considered**:
- Controller-only fix: Fastest but keeps query logic in the controller; acceptable as a minimal fix with a documented follow-up for repository.

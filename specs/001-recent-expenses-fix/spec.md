# Feature Specification: Recent Expenses Endpoint Returns Data When User Has Expenses

**Feature Branch**: `001-recent-expenses-fix`  
**Created**: 2025-03-07  
**Status**: Draft  
**Input**: User description: Recent expenses endpoint returns empty data when user has expenses; main list and summary show expenses correctly. Endpoint should return the latest expenses (e.g. last 10) in the same response format as the main list, including expenses in any status (e.g. pending).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Recent Expenses List Shows Latest Expenses (Priority: P1)

As a branch manager, when I have expenses in the system, I want the "recent expenses" endpoint to return my most recent expenses (e.g. last 10) so that dashboards and widgets can display them instead of an empty list.

**Why this priority**: The recent endpoint is broken for the common case (user has expenses); fixing it unblocks dashboard and any UI that relies on recent expenses.

**Independent Test**: Call the recent expenses endpoint as an authenticated branch manager who has at least one expense; response must include those expenses (up to the defined limit) in the same structure as the main expenses list, ordered by most recent first.

**Acceptance Scenarios**:

1. **Given** the user is authenticated as a branch manager and has at least one expense (in any status), **When** the user requests recent expenses, **Then** the response contains a non-empty list of expenses, ordered by most recent first, up to the defined limit (e.g. 10).
2. **Given** the user is authenticated and has no expenses, **When** the user requests recent expenses, **Then** the response contains an empty list and a success message.
3. **Given** the user has expenses in different statuses (e.g. pending, approved), **When** the user requests recent expenses, **Then** the response includes recent expenses regardless of status (no filtering out of pending or other statuses by default).
4. **Given** the recent expenses response includes data, **When** the response is parsed, **Then** each expense item has the same shape as in the main expenses list (e.g. id, expense_name, expense_type, amount, date, time, status, created_at) so clients can reuse the same UI/parsing logic.

### Edge Cases

- When the user has more expenses than the "recent" limit (e.g. 10), only the most recent N are returned; no pagination is required for this endpoint.
- When the user is not authenticated or not a branch manager, the endpoint must enforce existing authentication/authorization and return an appropriate error, not empty data.
- When the user has only pending expenses, the recent list must still include them (fix for current bug where pending were excluded).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The recent expenses endpoint MUST return the most recent expenses for the authenticated branch manager, ordered by creation date descending, up to a defined maximum (e.g. 10 items).
- **FR-002**: The recent expenses endpoint MUST include expenses in all statuses (e.g. pending, approved) by default so that when the user has expenses, the list is non-empty when appropriate.
- **FR-003**: The response structure for recent expenses MUST be consistent with the main expenses list response (same expense item shape: e.g. id, name, type, amount, date, time, status, created_at) so that clients can display recent expenses with the same UI and parsing.
- **FR-004**: The response MUST indicate success with a clear message and a data array (empty when the user has no expenses).
- **FR-005**: The endpoint MUST enforce the same authentication and authorization as the main expenses list (only the owning branch manager sees their recent expenses).

### Key Entities

- **Expense**: Represents a single expense record owned by a branch manager; has attributes such as name, type, amount, date, time, status, and timestamps. Recent expenses are a subset of the same entity, filtered and ordered by recency.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: When a branch manager has at least one expense, the recent expenses endpoint returns at least one item in the data array (no empty data when expenses exist).
- **SC-002**: Users can rely on the recent expenses list for dashboards and widgets without seeing incorrect empty results when they have expenses.
- **SC-003**: Response structure matches the main expenses list so that a single client implementation can display both full and recent lists without special handling for shape differences.

## Assumptions

- The defined limit for "recent" (e.g. 10) is acceptable; no requirement for a configurable limit in this feature.
- Existing authentication and authorization for the expense module apply unchanged to the recent endpoint.
- The main expenses list response shape is the reference for "same structure"; no new fields are required for this fix.

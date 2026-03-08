# Feature Specification: Timeline Sort and Inventory Details Timeline

**Feature Branch**: `002-timeline-sort-inventory-details`  
**Created**: 2026-03-08  
**Status**: Draft  
**Input**: User description: "Sort timeline by oldest first in detail endpoints; add same timeline model to Inventory details response (Expense/Purchase style: id, event_type, name, image, occurred_at)."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Chronological Timeline in All Detail Views (Priority: P1)

When a user (branch manager, brand owner, or other authorized role) opens the details of an expense, purchase order, or any entity that shows a timeline, they see events in chronological order: the oldest event first and the newest last. This allows them to follow the story of the entity from creation to the latest action without mental reordering.

**Why this priority**: Correct ordering is foundational for understanding audit trails and is expected by users in all modules that already expose timelines.

**Independent Test**: Call any detail endpoint that returns a timeline (e.g. expense details, order details). Assert that the `timelines` array is ordered by event time ascending (oldest first). Delivers value by making every detail view consistent and readable.

**Acceptance Scenarios**:

1. **Given** an expense with multiple timeline events (created, submitted, approved), **When** the user requests expense details, **Then** the response includes a `timelines` array ordered by event date/time from earliest to latest.
2. **Given** a purchase order with timeline events, **When** the user requests order details, **Then** the `timelines` in the response are ordered oldest first.
3. **Given** any entity that exposes a dedicated timeline endpoint (e.g. GET …/timeline), **When** the user requests that timeline, **Then** the returned list is ordered by event date/time ascending (oldest first).

---

### User Story 2 - Inventory Details Include Timeline in Same Shape as Expense (Priority: P2)

When a user opens the details of an inventory session (e.g. daily quick inventory session or monthly inventory), the response includes a timeline of events (created, started, submitted, reviewed, approved, rejected, etc.) in the same structure used for expenses and purchase orders. Each timeline entry includes: a unique identifier, the type of event, the name of the person who performed the action, an optional image for that person, and when the event occurred.

**Why this priority**: Parity across modules improves predictability for frontends and gives inventory the same audit/history experience as expenses and purchases.

**Independent Test**: Call the inventory session (or monthly inventory) details endpoint and assert the response contains a `timelines` array; each element has the same fields as in expense/purchase detail timelines (id, event_type, name, image, occurred_at). Delivers value by enabling a single UI component and consistent UX.

**Acceptance Scenarios**:

1. **Given** an inventory session that has timeline events, **When** the user requests session details (the endpoint that returns full session data), **Then** the response includes a `timelines` array where each item has: id, event_type, name, image (or null), and occurred_at.
2. **Given** a monthly inventory that has timeline events, **When** the user requests monthly inventory details, **Then** the response includes a `timelines` array with the same structure (id, event_type, name, image, occurred_at).
3. **Given** the same timeline data, **When** returned in inventory details vs in expense details, **Then** the shape of each timeline entry is identical (same field names and types) so clients can reuse one timeline component.

---

### Edge Cases

- When an entity has no timeline events, the detail response still includes a `timelines` key (or equivalent) as an empty array rather than omitting it or returning an error.
- When the same detail is loaded multiple times, timeline order is deterministic and always oldest-first.
- When an inventory session or monthly inventory has no events yet, the details response includes an empty timeline list in the same structure.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Every endpoint that returns entity details and includes a timeline MUST return the timeline ordered by event date/time ascending (oldest first, then newest).
- **FR-002**: Every dedicated timeline endpoint (e.g. GET …/timeline) for an entity MUST return events ordered by event date/time ascending.
- **FR-003**: The Inventory module’s details endpoint(s) for the relevant inventory types (e.g. daily quick session, monthly inventory) MUST include a timeline in the response.
- **FR-004**: Timeline entries in Inventory detail responses MUST use the same structure as timeline entries in Expense (and Purchase) detail responses: each entry MUST include an identifier, event type, actor name, optional actor image, and occurrence date/time.
- **FR-005**: When an entity has no timeline events, the detail response MUST still expose the timeline field as an empty array (consistent structure).

### Key Entities

- **Timeline**: A list of events for an entity; each event has an identifier, type, actor (name and optional image), and occurrence time. Ordered chronologically (oldest first).
- **Detail response**: The API response returned when a user requests full details for a single entity (expense, order, inventory session, etc.); it may include a timeline as part of that response.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Users see timeline events in chronological order (oldest first) in 100% of detail and timeline endpoints that return timelines.
- **SC-002**: Inventory detail responses include a timeline array in the same structure as Expense/Purchase timelines, so a single client-side timeline component can render all modules.
- **SC-003**: No detail or timeline endpoint returns events in reverse chronological order when this feature is complete; ordering is consistently ascending by time.
- **SC-004**: Empty timelines are represented as an empty array in the same structure, with no missing or inconsistent keys across modules.

## Assumptions

- All modules that currently return timelines in detail responses (Expense, Purchase, Custody, etc.) are in scope for sort-order change; any other module that adds timelines in the future should follow the same ordering and structure.
- “Same structure” means the same logical fields (id, event_type, name, image, occurred_at); field names in the API are assumed to match across Expense, Purchase, and Inventory for consistency.
- Inventory already has (or will have) a source of timeline events (e.g. session created, submitted, approved, rejected); this feature only requires exposing them in the details response in the unified shape and ensuring order.

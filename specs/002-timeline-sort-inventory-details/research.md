# Research: Timeline Sort and Inventory Details Timeline

**Feature**: 002-timeline-sort-inventory-details  
**Phase**: 0

## 1. Timeline sort order (oldest first)

**Decision**: All timeline data (in detail responses and dedicated timeline endpoints) MUST be ordered by event date/time **ascending** (oldest first, then newest).

**Rationale**: Spec and user request explicitly require chronological order so users can follow the story of an entity from creation to latest action. Reverse order (newest first) is common for feeds but not for audit-style detail views.

**Alternatives considered**:
- Keep newest-first: Rejected; contradicts spec (FR-001, FR-002) and user request.
- Configurable per module: Rejected; spec requires consistent behavior across all modules.

**Implementation note**: Change every `orderBy('..._at', 'desc')` (or equivalent) used for timeline relations or timeline queries to `orderBy('..._at', 'asc')` (or `orderBy('occurred_at', 'asc')` where applicable). Dedicated timeline endpoints and eager-loaded `timelines` in detail responses must use the same ordering.

---

## 2. Unified timeline entry shape

**Decision**: Timeline entries in API responses MUST use the same structure across Expense, Purchase, Custody, and Inventory: `id`, `event_type`, `name`, `image` (nullable), `occurred_at` (ISO datetime string). Existing `UnifiedTimelineResource` already implements this for Expense, Purchase, and Custody.

**Rationale**: Spec FR-004 and SC-002 require Inventory detail responses to use the same structure as Expense/Purchase so clients can reuse one timeline component and UX is consistent.

**Alternatives considered**:
- Keep Inventory-specific timeline shape (e.g. `InventorySessionTimelineResource` with `event_label`, `event_icon`, `title`, `description`, etc.): Rejected; spec requires same field names and types as Expense.
- New Inventory-only resource with same keys: Possible but duplicates logic; extending `UnifiedTimelineResource` to support Inventory models is preferred for single source of truth.

---

## 3. Where to apply sort-order changes

**Decision**: Apply ascending order at the source of timeline data:

- **Expense**: Repository `findForShow` eager-load `timelines` with `orderBy('created_at', 'asc')`. Controllers `ExpenseController::timeline()` and `ExpenseApprovalController::timeline()` use `orderBy('created_at', 'asc')`.
- **Purchase**: Model relations `timelines()` on PurchaseOrder, ReturnOrder, GoodsReceipt, PurchaseVariance, CompensatoryOrder: change `orderBy('occurred_at', 'desc')` to `orderBy('occurred_at', 'asc')`. `PurchaseOrderService::getOrderTimeline()`: change to `orderBy('occurred_at', 'asc')`.
- **Custody**: Relation `CustodyRequest::timeline()` already uses `orderBy('action_date')` (default ASC); make explicit `orderBy('action_date', 'asc')` for consistency.
- **Inventory (daily)**: `DailyQuickInventoryController::getTimelines()` already uses `orderBy('occurred_at', 'asc')`. For session details, load `timelines` with `orderBy('occurred_at', 'asc')` and include in `InventorySessionResource`.
- **Inventory (monthly)**: `MonthlyInventoryService::getTimelines()` currently `orderBy('occurred_at', 'desc')` → change to `asc`. For monthly detail `show()`, eager-load timelines with asc and include in `MonthlyInventoryResource`.

**Rationale**: Single place per module (relation or service method) ensures no endpoint returns desc-ordered timelines.

**Alternatives considered**: Sort in resource layer (e.g. in `toArray()`): Rejected; order should be guaranteed by data layer so all consumers (detail and timeline endpoints) get correct order without duplicating sort logic.

---

## 4. How to add Inventory timelines to detail responses

**Decision**:
- **Daily quick session details** (`getSession`): Eager-load `timelines` with `orderBy('occurred_at', 'asc')`. In `InventorySessionResource`, add key `timelines` using `UnifiedTimelineResource::collection($this->timelines)` when loaded. Extend `UnifiedTimelineResource` to recognize `InventorySessionTimeline` and `MonthlyInventoryTimeline` and output the same shape (id, event_type, name, image, occurred_at).
- **Monthly inventory details** (`MonthlyInventoryController::show`): Eager-load `timelines` with `orderBy('occurred_at', 'asc')`. In `MonthlyInventoryResource`, add `timelines` with `UnifiedTimelineResource::collection($this->timelines)` when loaded.

**Rationale**: Reusing `UnifiedTimelineResource` keeps one contract; Inventory timeline models already have `actor_name`, `actor_image` (or `actor_image_url`), `occurred_at`, and event type enum — mappable to the unified shape.

**Alternatives considered**: Separate Inventory timeline resource with same keys: Would work but duplicates formatting; one resource class is easier to maintain and keeps contract in one place.

---

## 5. Event type value for Inventory in unified shape

**Decision**: For `InventorySessionTimeline`, use a string derived from the existing enum value (e.g. `session_created`, `session_submitted`, etc.) so `event_type` remains a single string consistent with Expense (`expense_created`, …) and Purchase (enum value). For `MonthlyInventoryTimeline`, same approach (e.g. `monthly_inventory_created`, …). Exact naming to follow existing enum values in snake_case.

**Rationale**: Unified shape requires `event_type`; existing enums `DailyInventoryTimelineEventType` and `MonthlyInventoryTimelineEventType` already define events — use their value (or a prefixed variant) for consistency.

**Alternatives considered**: Leave as module-specific enums: Spec requires same structure; `event_type` as string is already the pattern in UnifiedTimelineResource for Expense/Purchase/Custody.

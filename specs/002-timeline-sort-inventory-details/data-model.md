# Data Model: Timeline Sort and Inventory Details Timeline

**Feature**: 002-timeline-sort-inventory-details  
**Phase**: 1

This feature does not introduce new tables or migrations. It constrains **ordering** of existing timeline data and **response shape** for timelines in Inventory detail endpoints. Existing entities are summarized for reference.

---

## Existing timeline entities (unchanged schema)

### Expense

- **ExpenseTimeline**: `expense_id`, `action`, `status`, `performed_by`, `performed_by_type`, `notes`, `created_at`. Relation: `Expense::timelines()`. Order for response: `created_at` ASC.

### Purchase (polymorphic)

- **OrderTimeline**: `timelineable_type`, `timelineable_id`, `event_type` (enum), `actor_name`, `actor_image_url`, `occurred_at`, etc. Used by PurchaseOrder, ReturnOrder, GoodsReceipt, PurchaseVariance, CompensatoryOrder. Order for response: `occurred_at` ASC.

### Custody

- **CustodyRequestTimeline**: `custody_request_id`, `stage`, `status`, `actor_name`, `actor_profile_image`, `action_date`, etc. Relation: `CustodyRequest::timeline()`. Order for response: `action_date` ASC.

### Inventory – Daily quick

- **InventorySessionTimeline**: `inventory_session_id`, `event_type` (DailyInventoryTimelineEventType), `actor_id`, `actor_type`, `actor_name`, `actor_image`, `actor_role`, `title`, `description`, `metadata`, `occurred_at`. Relation: `InventorySession::timelines()`. Order for response: `occurred_at` ASC.

### Inventory – Monthly

- **MonthlyInventoryTimeline**: `monthly_inventory_id`, `event_type` (MonthlyInventoryTimelineEventType), `actor_name`, `actor_image`, `occurred_at`, etc. Relation: `MonthlyInventory::timelines()`. Order for response: `occurred_at` ASC.

---

## Unified timeline entry (API output shape)

Used in all detail and timeline responses that include timelines. Not a stored entity; produced by `UnifiedTimelineResource` (and extended for Inventory).

| Field         | Type   | Required | Description |
|---------------|--------|----------|-------------|
| `id`          | string | Yes      | Unique identifier of the timeline record (UUID). |
| `event_type`  | string | Yes      | Event type (e.g. `expense_created`, `session_submitted`, order enum value). |
| `name`        | string | Yes      | Display name of the actor (person or system). |
| `image`       | string \| null | Yes | URL of actor image, or `null`. |
| `occurred_at` | string | Yes      | ISO datetime when the event occurred (`Y-m-d H:i:s`). |

**Validation**: N/A (output only). Empty timeline is represented as `[]`.

---

## State / ordering rules

- **Ordering**: For every endpoint that returns timelines (embedded in detail or dedicated timeline endpoint), the list MUST be ordered by the event’s date/time field **ascending** (oldest first).
- **Empty timelines**: When an entity has no timeline events, the response MUST still include the timeline key with value `[]` (empty array).

---

## Affected relations and load order

| Module    | Relation or query              | Current order        | Target order   |
|-----------|---------------------------------|----------------------|----------------|
| Expense   | `Expense::timelines` (findForShow) | `created_at` DESC    | `created_at` ASC |
| Expense   | ExpenseController/ExpenseApprovalController timeline() | `created_at` DESC | `created_at` ASC |
| Purchase  | PurchaseOrder, ReturnOrder, GoodsReceipt, PurchaseVariance, CompensatoryOrder `timelines()` | `occurred_at` DESC | `occurred_at` ASC |
| Purchase  | PurchaseOrderService::getOrderTimeline() | `occurred_at` DESC | `occurred_at` ASC |
| Custody   | CustodyRequest::timeline()      | `action_date` (default ASC) | `action_date` ASC (explicit) |
| Inventory (daily) | InventorySession::timelines (when loaded in getSession) | (none) | `occurred_at` ASC |
| Inventory (monthly) | MonthlyInventoryService::getTimelines() | `occurred_at` DESC | `occurred_at` ASC |
| Inventory (monthly) | MonthlyInventory::timelines (when loaded in show) | (none) | `occurred_at` ASC |

---

## Inventory event_type mapping (for UnifiedTimelineResource)

- **InventorySessionTimeline**: `event_type` in API = e.g. `inventory_session_submitted`, `inventory_session_approved`, … (prefix `inventory_session_` + enum value, or equivalent).
- **MonthlyInventoryTimeline**: `event_type` in API = e.g. `monthly_inventory_created`, `monthly_inventory_submitted`, … (prefix `monthly_inventory_` + enum value, or equivalent).

Name and image for both come from `actor_name` and `actor_image` (with URL resolution where applicable, e.g. `actor_image_url` on MonthlyInventoryTimeline).

# Quickstart: Timeline Sort and Inventory Details Timeline

**Feature**: 002-timeline-sort-inventory-details  
**Branch**: `002-timeline-sort-inventory-details`

## Goal

1. Return all timelines in **ascending** order (oldest first) in detail and timeline endpoints.
2. Include a **unified** timeline array in Inventory session and monthly inventory detail responses, same shape as Expense/Purchase (`id`, `event_type`, `name`, `image`, `occurred_at`).

## Prerequisites

- Laravel app, PHP 8.x, `app/Modules/` structure.
- Existing `UnifiedTimelineResource` (Expense, Purchase, Custody).
- Inventory modules: Daily Quick (InventorySession, InventorySessionTimeline), Monthly (MonthlyInventory, MonthlyInventoryTimeline).

## Implementation steps (high level)

### 1. Timeline sort (oldest first)

- **Expense**
  - `ExpenseRepository::findForShow()`: In the `with([..., 'timelines' => fn ($q) => ...])`, change to `orderBy('created_at', 'asc')`.
  - `ExpenseController::timeline()` and `ExpenseApprovalController::timeline()`: Change `orderBy('created_at', 'desc')` to `orderBy('created_at', 'asc')`.
- **Purchase**
  - In each model that defines `timelines()` (PurchaseOrder, ReturnOrder, GoodsReceipt, PurchaseVariance, CompensatoryOrder), change `orderBy('occurred_at', 'desc')` to `orderBy('occurred_at', 'asc')`.
  - `PurchaseOrderService::getOrderTimeline()`: Change `orderBy('occurred_at', 'desc')` to `orderBy('occurred_at', 'asc')`.
- **Custody**
  - `CustodyRequest::timeline()`: Ensure `orderBy('action_date', 'asc')` (explicit).
- **Inventory**
  - `MonthlyInventoryService::getTimelines()`: Change `orderBy('occurred_at', 'desc')` to `orderBy('occurred_at', 'asc')`.
  - Daily quick `getTimelines()` already uses `orderBy('occurred_at', 'asc')` — no change.

### 2. Inventory details: include timelines (unified shape)

- **UnifiedTimelineResource**
  - In `app/Http/Resources/UnifiedTimelineResource.php`, add branches for `InventorySessionTimeline` and `MonthlyInventoryTimeline`. Map to same array: `id`, `event_type` (e.g. prefixed enum value), `name` (actor_name), `image` (resolve URL from actor_image where needed), `occurred_at`.
- **Daily quick session details**
  - In `DailyQuickInventoryController::getSession()`, add `timelines` to the `with([...])` and ensure order: e.g. `'timelines' => fn ($q) => $q->orderBy('occurred_at', 'asc')`.
  - In `InventorySessionResource::toArray()`, add: `'timelines' => $this->whenLoaded('timelines', fn () => UnifiedTimelineResource::collection($this->timelines))`.
- **Monthly inventory details**
  - In `MonthlyInventoryController::show()` (or the method that loads the inventory for the response), eager-load `timelines` with `orderBy('occurred_at', 'asc')`.
  - In `MonthlyInventoryResource::toArray()`, add: `'timelines' => $this->whenLoaded('timelines', fn () => UnifiedTimelineResource::collection($this->timelines))`.

### 3. Verify

- Call expense detail and expense timeline: timelines ordered oldest → newest.
- Call purchase order detail (and any other purchase detail that includes timelines): same order.
- Call custody request detail: timeline ordered by action_date asc.
- Call daily quick session detail: response includes `timelines` array in unified shape, asc.
- Call monthly inventory detail: response includes `timelines` array in unified shape, asc.
- Call dedicated timeline endpoints (e.g. monthly getTimelines): order asc.

### 4. Tests

- **Feature tests**: For at least one detail and one timeline endpoint per module, assert that the `timelines` (or `timeline`) array is ordered by date/time ascending (e.g. compare consecutive `occurred_at` or equivalent).
- **Feature tests**: For Inventory session detail and monthly inventory detail, assert presence of `timelines` and that each entry has `id`, `event_type`, `name`, `image`, `occurred_at`.
- **Edge case**: Entity with zero timeline events returns `timelines: []` (or `timeline: []`), not missing key.

## Key files (reference)

| Purpose | Path |
|--------|------|
| Unified timeline output | `app/Http/Resources/UnifiedTimelineResource.php` |
| Expense detail load | `Modules/Expense/app/Repositories/ExpenseRepository.php` (findForShow) |
| Expense timeline endpoints | `Modules/Expense/app/Http/Controllers/ExpenseController.php`, `ExpenseApprovalController.php` |
| Purchase timeline relations | `Modules/Purchase/app/Models/PurchaseOrder.php`, ReturnOrder, GoodsReceipt, PurchaseVariance, CompensatoryOrder |
| Purchase order timeline service | `Modules/Purchase/app/Services/PurchaseOrderService.php` (getOrderTimeline) |
| Custody timeline relation | `Modules/Custody/app/Models/CustodyRequest.php` |
| Daily session detail | `Modules/Inventory/app/Http/Controllers/DailyQuickInventoryController.php` (getSession) |
| Daily session resource | `Modules/Inventory/app/Transformers/InventorySessionResource.php` |
| Monthly detail | `Modules/Inventory/app/Http/Controllers/MonthlyInventoryController.php` (show) |
| Monthly resource | `Modules/Inventory/app/Transformers/MonthlyInventoryResource.php` |
| Monthly getTimelines | `Modules/Inventory/app/Services/MonthlyInventoryService.php` (getTimelines) |

## Spec and plan

- **Spec**: [spec.md](../spec.md)  
- **Plan**: [plan.md](../plan.md)  
- **Research**: [research.md](../research.md)  
- **Data model**: [data-model.md](../data-model.md)  
- **Contract**: [contracts/timeline-detail-response.md](timeline-detail-response.md)

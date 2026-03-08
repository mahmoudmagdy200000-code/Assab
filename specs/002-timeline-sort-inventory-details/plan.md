# Implementation Plan: Timeline Sort and Inventory Details Timeline

**Branch**: `002-timeline-sort-inventory-details` | **Date**: 2026-03-08 | **Spec**: [spec.md](./spec.md)  
**Input**: Feature specification from `/specs/002-timeline-sort-inventory-details/spec.md`

**Note**: This template is filled in by the `/speckit.plan` command. See `.specify/templates/plan-template.md` for the execution workflow.

## Summary

1. **Timeline sort**: Every detail and dedicated timeline endpoint must return timeline events ordered by event time **ascending** (oldest first). Affected modules: Expense, Purchase (orders, returns, goods receipts, variances, compensatory orders), Custody, and Inventory (daily and monthly timeline endpoints).
2. **Inventory details timeline**: Daily quick inventory session details and monthly inventory details responses must include a `timelines` array. Each entry must use the same shape as Expense/Purchase: `id`, `event_type`, `name`, `image`, `occurred_at`. Implementation reuses `UnifiedTimelineResource` by extending it to support Inventory session and monthly inventory timeline models.

## Technical Context

**Language/Version**: PHP 8.x  
**Primary Dependencies**: Laravel, modular structure under `app/Modules/`  
**Storage**: Existing DB; no schema changes. Timeline data lives in `expense_timelines`, `order_timelines` (polymorphic), `custody_request_timeline`, `inventory_session_timelines`, `monthly_inventory_timelines`.  
**Testing**: PHPUnit; feature tests for API endpoints, unit tests for services.  
**Target Platform**: Linux server (Laravel API).  
**Project Type**: Web service (REST API).  
**Performance Goals**: API response ≤500ms for 95% of requests (constitution).  
**Constraints**: No N+1; eager load timelines with explicit order; consistent response shape across modules.  
**Scale/Scope**: All modules that expose timelines (Expense, Purchase, Custody, Inventory).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|-----------|--------|--------|
| **I. Code Quality** | Pass | Changes are in repositories (eager-load order), resources (UnifiedTimelineResource), and detail resources. No business logic in controllers; ordering is a data-presentation concern. |
| **II. Testing Standards** | Pass | Feature tests for detail/timeline endpoints (order + presence of timelines); unit tests for any new formatter logic if needed. |
| **III. User Experience Consistency** | Pass | Feature enforces consistent timeline shape and order across modules. |
| **IV. Performance Requirements** | Pass | No new queries; only order and optional eager load of timelines. Response size increase is one extra relation when loading inventory details. |
| **V. Security First** | Pass | No new endpoints; existing auth/authorization on detail endpoints unchanged. |

## Project Structure

### Documentation (this feature)

```text
specs/002-timeline-sort-inventory-details/
├── plan.md              # This file
├── research.md          # Phase 0
├── data-model.md        # Phase 1
├── quickstart.md        # Phase 1
├── contracts/           # Phase 1 (API timeline shape)
└── tasks.md             # Phase 2 (/speckit.tasks – not created by /speckit.plan)
```

### Source Code (repository root)

```text
app/
├── Http/Resources/
│   └── UnifiedTimelineResource.php   # Extend for Inventory timeline models
├── Modules/
│   ├── Expense/
│   │   ├── app/Repositories/ExpenseRepository.php        # findForShow: timelines asc
│   │   └── app/Http/Controllers/ExpenseController.php     # timeline(): asc
│   │   └── app/Http/Controllers/ExpenseApprovalController.php  # timeline(): asc
│   ├── Purchase/
│   │   ├── app/Models/PurchaseOrder.php                  # timelines(): orderBy asc
│   │   ├── app/Models/ReturnOrder.php                   # timelines(): orderBy asc
│   │   ├── app/Models/GoodsReceipt.php                   # timelines(): orderBy asc
│   │   ├── app/Models/PurchaseVariance.php              # timelines(): orderBy asc
│   │   ├── app/Models/CompensatoryOrder.php              # timelines(): orderBy asc
│   │   └── app/Services/PurchaseOrderService.php        # getOrderTimeline(): asc
│   ├── Custody/
│   │   └── app/Models/CustodyRequest.php                # timeline(): explicit asc
│   └── Inventory/
│       ├── app/Http/Controllers/DailyQuickInventoryController.php  # getSession: load timelines asc; add to resource
│       ├── app/Http/Controllers/MonthlyInventoryController.php     # show: load timelines asc; add to resource
│       ├── app/Transformers/InventorySessionResource.php          # add timelines (UnifiedTimelineResource)
│       ├── app/Transformers/MonthlyInventoryResource.php            # add timelines (UnifiedTimelineResource)
│       └── app/Services/MonthlyInventoryService.php      # getTimelines(): asc (already has dedicated endpoint)
```

**Structure Decision**: Single Laravel app with modules. Timeline sort changes are confined to relation definitions, repository eager-load constraints, and service methods that return timeline collections. Inventory gains timeline inclusion in existing detail resources and optional extension of `UnifiedTimelineResource` for Inventory models.

## Complexity Tracking

No constitution violations. Table left empty.

# Data Model: Refactor Modules (No Schema Change)

**Feature**: 001-refactor-modules-optimization  
**Phase**: 1

## Scope

This feature is a **refactor and optimization** of existing code. **No database schema, migrations, or entity attribute changes are in scope.** The data model remains as-is; this document records the existing domains and entities only for context.

## Existing Entities (Unchanged)

### Expense Module

- **Expense / Quick cash / Invoices**: Expenses (quick cash, single invoice, grouped invoice), categories, suppliers, attachments, pre-approval requests, approvals.
- **Relations**: Categories (parent/children), suppliers, branch, attachments, approval workflow.

### Shift Module

- **Shifts**: Pending, in-progress, completed shifts; handover, variance, reassignment; cashier management.
- **Relations**: Cashiers, branches, shift states, requests.

### Purchase Module

- **Orders / History / Returns**: Purchase history, new/pending orders, goods receiving, return management, supplier info.
- **Relations**: Suppliers, branches, items, order state/timeline.

## Validation Rules

All validation rules remain as currently implemented. When refactoring (e.g. moving validation into Form Requests), the same rules and message output must be preserved so API responses stay identical.

## State Transitions

No change to business states or transitions. Refactor must preserve the same side effects (database state, notifications, queue jobs) for the same inputs.

## Refactor Impact on Data

- **Reads**: May be optimized (eager loading, selective columns) only where response output is unchanged.
- **Writes**: Logic may move from controllers to services/repositories; transaction boundaries and commit/rollback behavior must remain the same.
- **New tables/columns**: None for this feature.

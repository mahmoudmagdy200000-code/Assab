# Refactor Pattern: Controller → Service → Repository

**Feature**: 001-refactor-modules-optimization  
**Applied in**: Phase 2 (Expense SupplierController as template)

## Rule

1. **Controller**: HTTP only. Validate input (or use Form Request), call Service or Repository, return same response (same Resource/array, same status code). No business logic, no direct Eloquent calls.
2. **Service**: Business logic and orchestration. May call Repository or Model. Returns data (model, DTO, array) for the controller to format.
3. **Repository**: Data access only. Queries (find, list, create, update, delete). Returns model(s). No response formatting.

## Template (Expense SupplierController)

- **Before**: `SupplierController::show()` called `Supplier::findOrFail($supplier)` and returned `successResponse(SupplierResource(...))`.
- **After**:
  - `SupplierRepository::findOrFail(string $id): Supplier` holds the query.
  - `SupplierController::show()` injects `SupplierRepository`, calls `$this->supplierRepository->findOrFail($supplier)`, returns `successResponse(new SupplierResource($supplierModel), ...)`.
- **Response**: Unchanged (same JSON, same status).
- **Index**: Still uses `ExpenseHelperService::getSuppliers()` (service returns list shape); no repository required for that path until we extract list query into a repository.

## Checklist for each controller method

- [ ] No `Model::` or `DB::` in controller (move to Repository or Service).
- [ ] Validation in controller or Form Request; same rules and messages (no response change). See checklists/validation-preservation.md (T013).
- [ ] Controller calls at most: Form Request / validation → Service or Repository → Resource/response.
- [ ] Run tests after change; fix any regression before next refactor.

## Shift and Purchase

Apply the same pattern: add or use a Repository for data access, keep controllers thin, preserve response body and status code.

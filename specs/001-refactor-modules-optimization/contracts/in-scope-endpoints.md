# In-Scope API Endpoints (T002)

**Purpose**: Document all API endpoints in Expense, Shift, and Purchase modules so verification can target every affected route. Refactor MUST NOT change response (body, status, error format) for any endpoint listed below.

**Source**: `Modules/Expense/routes/api.php`, `Modules/Shift/routes/api.php`, `Modules/Purchase/routes/api.php` (per contracts/README.md).

---

## Expense Module

**Route file**: `Modules/Expense/routes/api.php`  
**Prefixes**: `branch-manager/expenses`, `brand-owner/expenses` (API prefix may apply per main `routes/api.php`)

### branch-manager/expenses (auth:sanctum, branch.manager.or.cashier)

| Method | Path | Controller::method |
|--------|------|--------------------|
| GET | /categories | CategoryController::index |
| GET | /categories/parent-categories | CategoryController::getParentCategories |
| GET | /categories/{category}/subcategories | CategoryController::getSubcategories |
| GET | /categories/{category}/children | CategoryController::children |
| GET | /categories/{category} | CategoryController::show |
| POST | /categories | CategoryController::store |
| PUT | /categories/{category} | CategoryController::update |
| DELETE | /categories/{category} | CategoryController::destroy |
| GET | /suppliers | SupplierController::index |
| GET | /suppliers/{supplier} | SupplierController::show |
| GET | /quick-cash/ | ExpenseController::quickCashList |
| POST | /quick-cash/ | QuickCashExpenseController::store |
| PUT | /quick-cash/{expense} | QuickCashExpenseController::update |
| DELETE | /quick-cash/{expense} | QuickCashExpenseController::destroy |
| POST | /quick-cash/calculate-vat | QuickCashExpenseController::calculateVAT |
| GET | /single-invoice/ | ExpenseController::singleInvoiceList |
| GET | /single-invoice/previous | SingleInvoiceExpenseController::getPreviousInvoices |
| POST | /single-invoice/ | SingleInvoiceExpenseController::store |
| PUT | /single-invoice/{expense} | SingleInvoiceExpenseController::update |
| POST | /single-invoice/{expense}/duplicate | SingleInvoiceExpenseController::duplicate |
| GET | /pre-approval/ | ExpenseController::preApprovalList |
| POST | /pre-approval/ | PreApprovalRequestController::store |
| PUT | /pre-approval/{expense} | PreApprovalRequestController::update |
| GET | /pre-approval/previous | PreApprovalRequestController::getPreviousRequests |
| POST | /pre-approval/{expense}/duplicate | PreApprovalRequestController::duplicate |
| GET | /grouped-invoice/ | ExpenseController::groupedInvoiceList |
| POST | /grouped-invoice/ | GroupedInvoiceExpenseController::store |
| PUT | /grouped-invoice/{expense} | GroupedInvoiceExpenseController::update |
| GET | / | ExpenseController::index |
| GET | /summary | ExpenseController::summary |
| GET | /recent | ExpenseController::recent |
| GET | /drafts | ExpenseController::drafts |
| GET | /filter | ExpenseController::filter |
| GET | /search | ExpenseController::search |
| GET | /{expense} | ExpenseController::show |
| GET | /{expense}/timeline | ExpenseController::timeline |
| POST | /{expense}/submit | ExpenseController::submit |
| POST | /{expense}/resubmit | ExpenseController::resubmit |
| DELETE | /{expense} | ExpenseController::destroy |
| GET | /{expense}/attachments/ | ExpenseAttachmentController::index |
| POST | /{expense}/attachments/ | ExpenseAttachmentController::store |
| DELETE | /{expense}/attachments/ | ExpenseAttachmentController::destroy |
| POST | /scan-qr | ExpenseController::scanQRCode |
| POST | /scan-invoice | ExpenseController::scanInvoiceCode |

### brand-owner/expenses (auth:sanctum, brand.owner)

| Method | Path | Controller::method |
|--------|------|--------------------|
| GET | / | ExpenseApprovalController::index |
| GET | /{expense} | ExpenseApprovalController::show |
| POST | /{expense}/view | ExpenseApprovalController::markAsViewed |
| POST | /{expense}/approve | ExpenseApprovalController::approve |
| POST | /{expense}/reject | ExpenseApprovalController::reject |
| PUT | /{expense}/edit | ExpenseApprovalController::edit |
| GET | /{expense}/timeline | ExpenseApprovalController::timeline |

---

## Shift Module

**Route file**: `Modules/Shift/routes/api.php`  
**Prefixes**: `branch-manager`, `branch-manager/workday`, `cashier`, `shifts` (shared)

### branch-manager (auth:sanctum, branch.manager.or.cashier)

- **cashiers**: GET /, GET /all, POST /, GET /check-email, POST /check-email, GET /search, GET /filter, GET /available-cashiers, GET /{cashier}, PUT /{cashier}, GET /{cashier}/shifts
- **shifts/cashiers**: GET shifts/cashiers, GET shifts/cashiers/filter, GET shifts/cashiers/{shift}
- **shifts/pending**: GET shifts/pending/, GET shifts/pending/{shift}, GET shifts/pending/cashiers/{cashier}
- **shifts/in-progress**: GET shifts/in-progress/, GET shifts/in-progress/{shift}
- **shifts/completed**: GET shifts/completed/, GET shifts/completed/{shift}
- **shifts/reassigned**: GET shifts/reassigned/, GET shifts/reassigned/{shift}
- **requests**: GET requests/handovers, GET requests/variances
- **shifts/handover/summary**: GET
- **shifts/variance/statistics**: GET
- **shifts/variance-alerts**: GET /, POST {alert}/acknowledge
- **shifts**: GET /, POST calculate-sales, POST start-by-manager/{shiftId}, GET {shift}/, POST {shift}/reassign, POST {shift}/reassign-with-handover, GET {shift}/available-cashiers, POST {shift}/end, POST {shift}/end-with-handover, POST {shift}/start-handover, GET {shift}/available-recipients
- **shifts/{shift}/handover**: POST /, POST approve, POST reject, GET status, GET available-cashiers, GET rejection-details, POST rejection-decision
- **shifts/{shift}/variance**: POST /, GET /
- **shifts/{shift}/responsibility**: GET details, POST approve, POST reject

### branch-manager/workday (auth:sanctum, branch.manager)

- GET current, POST start, GET details, GET handoffs, GET handoffs/cashier/{handoverId}, POST handoffs/approve, POST handoffs/reject, GET handoffs/rejection/{shift}, POST handoffs/rejection/{shift}/decision, POST end, GET final-handover/{shiftId}, GET daily-close, PUT daily-close, POST daily-close/submit, POST daily-close/reopen, GET history

### cashier (auth:sanctum, cashier)

- GET my-shifts, GET my-shifts/pending, GET my-shifts/in-progress, GET my-shifts/completed, GET my-shifts/reassigned, GET requests/handovers, GET requests/variances, GET requests/reassigned-shifts, GET my-shifts/{shift}/responsibility/details, POST my-shifts/{shift}/responsibility/approve, POST my-shifts/{shift}/responsibility/reject, GET my-shifts/{shift}, POST shifts/{shift}/start, POST shifts/{shift}/reassign/accept, POST shifts/{shift}/reassign/reject, POST shifts/{shift}/end, POST shifts/{shift}/end-with-handover, POST shifts/{shift}/start-handover, GET shifts/{shift}/available-recipients, POST shifts/calculate-sales, POST shifts/{shift}/handover/, POST accept, POST reject, POST edit, GET status, GET available-cashiers, GET shifts/history, GET shifts/weekly-summary

### shifts (auth:sanctum, shared)

- GET {shift}/details, GET {shift}/progress

---

## Purchase Module

**Route file**: `Modules/Purchase/routes/api.php`  
**Prefix**: `v1/purchase` (auth:sanctum)

### history

- GET /, GET /{id}, GET /{id}/timeline

### orders

- GET /branch-items, POST /compare-prices, GET /suppliers, GET /branches, GET /transfer-items, GET /direct-supplier-items, GET /supplier-items, GET /purchasing-officer-items, GET /, POST /, GET /{id}/summary, GET /{id}/supplier-info, PUT /{id}/items, POST /{id}/submit, DELETE /{id}/draft

### pending

- GET /, GET /{id}, GET /{id}/timeline, POST /{id}/approve, POST /{id}/partial-approve, POST /{id}/reject, POST /{id}/cancel, POST /{id}/approve-transfer, POST /{id}/approve-modifications, POST /{id}/reject-modifications, POST /{id}/items/{itemId}/approve, POST /{id}/items/{itemId}/reject, POST /{id}/items/{itemId}/cancel, GET /{id}/items/{itemId}/modification, POST approve/reject modification, GET get-cancellation-reason, POST /{id}/delay/approve, POST /{id}/delay/reject
- **pending/direct-supplier**: GET /, GET /{id}, POST /{id}/approve-delay, POST /{id}/reject-delay
- **pending/via-purchasing-officer**: GET /, GET /{id}, POST approve-delay, reject-delay
- **pending/internal-transfer**: GET /, GET /{id}

### receiving

- GET /in-progress, /orders, /drafts, /missing, /completed, POST /without-order, POST /orders/{orderId}/start, POST /orders/{orderId}/receive-internal-transfer, GET /{id}, GET /{id}/summary, PUT /{id}/delivery-details, POST /{id}/items/{itemId}/inspect, POST /{id}/unlisted-item, PUT /{id}/document-type, POST /{id}/invoice, POST /{id}/delivery-note, POST /{id}/receipt-without-document, POST /{id}/complete, POST /{id}/save-draft, DELETE /{id}/draft, GET /drafts/{id}, GET /missing/{id}, GET /completed/{id}, GET /orders/{orderId}/tracking, GET /orders/{orderId}/inspection, GET /receipts/{receiptId}/variance-summary, POST /variances/{varianceId}/action, POST supplier-response, accept-rejection, escalate

### returns

- GET /in-progress, /drafts, /completed, POST /, GET /{id}, GET /{id}/supplier-info, PUT /{id}, GET /{id}/timeline, POST /{id}/submit, POST /{id}/accept-rejection, POST /{id}/escalate, POST /draft, DELETE /{id}/draft

### suppliers

- GET /, GET /{id}, GET /{id}/items, GET /{id}/orders, GET /{id}/statistics

---

**Note**: Exact full URL path depends on main API prefix (e.g. `api/` or `api/v1/`). Use the route files above as the source of truth for which controller methods and paths are in scope.

# Validation Preservation (T013)

**Goal**: When validation is moved to Form Requests in Expense, Shift, or Purchase, preserve exact validation rules and message keys/values so error responses stay identical (spec FR-002, research §5).

## Rule

- **Rules**: Any Form Request used for an endpoint in these modules MUST use the same validation rules (same attributes, same rules string) as the current inline validation or existing Form Request.
- **Messages**: Custom message keys/values MUST be preserved. If the current code uses Laravel default messages, the Form Request MUST not override them with different text (or must use the same text). Do not "improve" or reword validation messages in this refactor.
- **Structure**: Error response format (e.g. `errors` object, 422 status) MUST remain unchanged.

## Where validation currently lives (in-scope modules)

- **Expense**: Inline `Validator::make()` or `$request->validate()` in ExpenseController, ExpenseApprovalController, ExpenseAttachmentController, QuickCashExpenseController, SingleInvoiceExpenseController, GroupedInvoiceExpenseController, PreApprovalRequestController, CategoryController. No Form Request directory in Expense yet.
- **Shift**: Form Requests exist (e.g. HandoverApprovalRequest, CashierShiftRequest, ReassignShiftRequest, EndShiftRequest, RecordHandoverRequest, CheckCashierEmailRequest, StoreCashierWithShiftsRequest). When changing or adding rules, preserve existing rules and messages.
- **Purchase**: Form Requests widely used (CreateReturnRequest, CreateInvoiceRequest, ReceiveGoodsRequest, etc.). When changing or adding rules, preserve existing rules and messages.

## Checklist when introducing or changing Form Requests

- [ ] Rules array matches previous inline rules or existing Form Request (attribute names and rule strings).
- [ ] No new custom messages unless they match the previous response text.
- [ ] After change, trigger the same validation failure (e.g. missing required field) and confirm error body and status code match.

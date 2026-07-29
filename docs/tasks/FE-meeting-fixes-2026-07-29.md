# BE changes — meeting 2026-07-29 (سيلز/اعتمادات/موردين) — deadline 07-31

Backend fixes for the 2026-07-29 meeting. Copy the FE sections into the dashboard/mobile task boards. Envelopes unchanged: list = `{ success, message, data, meta }` (mobile) / `{ data, meta }` + `{ error: { code, message, messageAr }, requestId }` (dashboard v1).

## What the meeting asked vs. what was already true

The approval pipeline the meeting described **already exists and matches the decision**: `pending → approved (محاسب/رئيس) → final-approved (رئيس الحسابات فقط) → rejected + إرجاع للمراجعة`, with reject-reason lookups and a full audit trail (`GET /api/v1/operations/{id}/audit-trail`). The complaints were about **data not reaching that pipeline** (unlinked IDs) and **missing filters/scoping**. That is what changed.

---

## 1. Mobile: supplier picker is now brand-scoped (فرونت الموبايل)

`GET /api/branch-manager/expenses/suppliers` no longer returns every supplier in the platform. It returns **only**:

- suppliers uploaded/created for the caller's **own brand** (`asab_suppliers.brand_id`), plus
- company-wide suppliers not pinned to any brand.

Rules:

- A branch not linked to any ASAB brand/company gets an **empty list** (fail-closed). Show "لا يوجد موردون مرتبطون بعلامتك التجارية" — not a spinner, not all suppliers.
- `?search=` still works (server-side name filter).
- Old legacy suppliers that were never linked to a brand (the «صابرين / محمد وأحمد» rows from the meeting) disappear from the picker by design.

**Writes are enforced too**: any expense create/update (quick-cash, single-invoice, grouped, pre-approval) naming a supplier outside the caller's brand now fails validation (422) with:

```
المورد المحدد غير متاح لعلامتك التجارية — اختر مورداً من قائمة موردي العلامة.
```

Show it as an inline field error on the supplier field.

## 2. Dashboard: brand filter on the operations list (فرونت الداشبورد)

`GET /api/v1/operations` (and the company-portal alias) accepts a new **`brandId`** query param alongside the existing `moduleKey, status, branchId, dateFrom, dateTo, search, origin`. It narrows to that brand's branches and also narrows `meta.summary` counts. It ANDs with the caller's assigned scope (can only narrow).

This is the meeting's «فلترة المبيعات/المصروفات بالعلامة»: add a brand dropdown to the sales (`moduleKey=sales`) and expenses (`moduleKey=expenses`) tabs; keep the existing branch/date/status filters.

## 3. Dashboard: expense operations now carry the mobile identity (fixes «UID غلط / بيانات ناقصة»)

`payload` of an expenses operation gained:

```json
{
  "supplierId": "uuid|null",        // mobile suppliers.id picked on the expense
  "supplierName": "مورد الكهرباء",   // render THIS instead of a bare id
  "legacyExpenseId": "uuid",        // the mobile expense id (support/debug)
  "submittedBy": "مدير فرع التحلية",
  "invoices": [
    { "invNum": "ELEC-1",
      "vendor": "مورد الكهرباء",     // now falls back: tax name → invoice supplier → expense supplier
      "supplierId": "uuid" }
  ]
}
```

Replace any UI that showed the raw op/public id as the "supplier" with `supplierName` / `invoices[].vendor`.

## 4. Ops runbook: no more silently lost invoices/shifts (باك/DevOps)

Every bridge skip is now logged with a reason + fix hint (`grep "bridge: skipped"`): `BRANCH_MISSING`, `BRANCH_GONE`, `BRANCH_UNLINKED`, `CASHIER_NOT_MIRRORED`, `NO_ASAB_ACTOR`.

New command:

```bash
php artisan asab:bridge-backfill --dry-run   # list stranded expenses/shifts + why
php artisan asab:bridge-backfill             # re-bridge them (idempotent)
```

Flow for the meeting's «الفاتورة اتبعتت ومجاتش»: link the branch (`PATCH /api/v1/admin/branches/{id}` with `restaurantId`) or mirror the cashier (`php artisan asab:mirror-mobile-cashiers`), then run the backfill. Records enter the accountant inbox as normal `pending` ops.

Suggested after every deploy/import: run `--dry-run` and check the stranded count is 0.

## 5. Still open (needs product/FE decision — NOT built)

- **Items-under-category on mobile** («اختار معدات → يجيب التلاجة والبوتاجاز»): the mobile `expense_items` table is transactional (rows belong to a submitted expense), there is no master item list per category on the mobile side. The dashboard master catalog (`asab_inventory_catalog_items`, brand-scoped, has category) is the natural source — needs an agreed mobile endpoint + app change. Proposal: `GET /api/branch-manager/expenses/categories/{id}/items` reading the brand catalog. Decide contract, then it's a small BE task.
- **Per-brand category isolation**: mobile `categories` is a shared global table (one unified taxonomy — matches the meeting's «توحيد التصنيفات»). True per-brand category lists need a tenant column + read-path change: postpone unless explicitly required.
- Rejection-reason enum, aggregator seeds, fixed-asset assignment screen — unchanged, still waiting on product (see IMPLEMENTATION-STATUS-2026-07-25).

## Tests

`SupplierBrandScopeTest` (5), `OperationBrandFilterTest` (1), `BridgeBackfillCommandTest` (3), `ExpenseBridgeTest` (+1 enrichment, 7 total) — all green. Pint clean.

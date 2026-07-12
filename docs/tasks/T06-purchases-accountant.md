# T06 — Purchases (Accountant 3-way Match)
> SRS: §7 ACC-3 (KPIs, PO rows, 3-way match ordered/received/invoice, detail modal columns, توثيق document action, edit line items price/qty, reject-with-reason, approved-suppliers tab, return orders, 3 order sources: supplier/other-branch/purchasing-manager) · Audited: 2026-07-10 · FE doc deliverable: docs/fe-wiring/FE-T06-purchases-accountant.md
> Status: ✅ done — shipped 2026-07-12 · 24 Pest tests green · Pint clean · FE doc delivered
> Decision (team-lead): built as **2-way (ordered↔received) + price verification**, match state
> modelled in 3 dimensions so the supplier-invoice leg slots in later with no payload reshape.
> The accountant-entered `unitPriceHalalas` is the invoice-price proxy and **counts toward `match`**
> (closes edge-case E6: a price divergence with matching quantity now flips the badge to `diff`).

## 1. Endpoint inventory (audited against code)

Purchases ride on the generic operations pipeline (`module_key = purchases`, data in `asab_operations.payload`).
All paths under `/api/v1`. Handlers verified in-body, not from the route line.

| # | Method | Path | Handler | Status | Notes |
|---|---|---|---|---|---|
| 1 | GET | `/operations?moduleKey=purchases` | `Operations/OperationController@index` | 🟡 | routes L247. Pagination + filters `moduleKey,status,branchId,match`, `search` on public_id only; branch-scoped via `scopeToAssignedBranches`. Missing for ACC-3.2/3.4: `supplierId` + date-range filters; row fields `supplierName/receiveDate/itemCount/receivedTotal` absent from `present()` (returns envelope only: amount, match, diffNote, attachmentCount) |
| 2 | GET | `/operations/export?moduleKey=purchases` | `Company/ExportController@operationsExport` → `ExportService::operations` | ✅ | routes L249 (role accountant,head). Purchases-specific sheet رقم الطلب/المورد/الفرع/الإجمالي (ر.س)/عدد الأصناف/حالة الاعتماد/حالة الإرسال; honours status/branch/brand/dateFrom/dateTo (ExportService.php:346–365, 412–438) |
| 3 | POST | `/operations/bulk-approve` | `OperationController@bulkApprove` → `OperationService::bulkApprove` | ✅ | routes L250. Ids pre-filtered to caller branch scope; per-id failure report |
| 4 | GET | `/operations/{id}` | `OperationController@show` | 🟡 | routes L251. Returns raw `payload` + auditTrail. Purchases payload is only `{supplierId, items:[{itemId,qty}], description, urgency, deliveryDate}` (procurement-created) or `{item, qty, unit, urgency, kind:'branch_request'}` (branch request) — no 3-way match lines, no supplier-name resolution, no attachments array, no summary tiles (ACC-3.3) |
| 5 | GET | `/operations/{id}/audit-trail` | `OperationController@auditTrail` | ✅ | routes L252. Steps with stage icon, actor, terminal flag |
| 6 | POST | `/operations/{id}/approve` | `OperationController@approve` → `OperationService::approve` | ✅ | routes L253 (accountant,head). Atomic pending→approved + ApprovalStep + notify head |
| 7 | POST | `/operations/{id}/reject` | `OperationController@reject` → `OperationService::reject` | ✅ | routes L254 (accountant,head). `reason` required («رفض مع ذكر السبب»), 409 `OP_ALREADY_FINAL` on final/rejected, notifies submitter |
| 8 | POST | `/operations/{id}/final-approve` | `OperationController@finalApprove` | ✅ | routes L256 (head; T10 scope — the ACC-3.4 «close» maps here) |
| 9 | POST | `/operations/{id}/correction` | `OperationController@correction` → `OperationService::correction` | ✅ | routes L257. Linked corrective op, PUR- prefix preserved |
| 10 | GET | `/lookups/suppliers` | `Shared/LookupController@suppliers` | 🟡 | routes L275. Reads LEGACY `Modules\Supplier\Models\Supplier` (id+name only, try/catch-wrapped) — NOT `asab_suppliers`; unusable for the approved-suppliers tab |
| 11 | GET | `/accountant/operations?moduleKey=purchases` | `Accountant/AccountantController@operations` | 🟡 | routes L344. Generic list + summary (totalUploaded/underReview/approved/rejected) — no purchases KPI (today totals, qty-discrepancy count), no supplier/date filters |
| 12 | GET | `/company/me/operations/export` | same as #2 | ✅ | routes L622 (company surface alias, role accountant) |
| 13 | GET | `/company/me/operations` | same as #1 | 🟡 | routes L623. Same gaps as #1 |
| 14 | POST | `/company/me/operations/bulk-approve` | same as #3 | ✅ | routes L624 |
| 15 | POST | `/company/me/operations/{id}/approve` | same as #6 | ✅ | routes L625 |
| 16 | POST | `/company/me/operations/{id}/notes` | `AccountantController@addNote` | ✅ | routes L632. Appends `payload.accountantNotes[]` atomically |
| 17 | GET | `/company/me/operations/{id}/export` | `Company/ExportController@operation` → `ExportService::operation` | 🟡 | routes L633. Single-op sheet; purchases lines fall back to label+qty (ExportService.php:198–207) — no المطلوب/المستلم/الفرق columns |
| 18 | GET | `/company/me/suppliers` | `Procurement/ProcurementController@suppliers` | 🟡 | routes L752–754 (role list includes accountant). Tenant-scoped `AsabSupplier` (BelongsToTenant), search/category/status filters, pagination; returns rating (0–50 = stars×10)/status/contacts — missing SRS card fields: items count + monthly order count (ACC-3.2) |
| 19 | GET | `/company/me/suppliers/export` | `ExportService::companySuppliers` | ✅ | routes L753. Includes عدد الطلبات per supplier (payload→supplierId group count, ExportService.php:499–512) |
| 20 | POST | `/company/me/operations/{id}/document` (توثيق) | — | ❌ | No route/handler anywhere (grep توثيق/document over routes + controllers: only attachment-level and expense-invoice verify) |
| 21 | PATCH | `/company/me/operations/{id}/purchase-lines/{rowId}` | — | ❌ | No purchase line editor. `salesLineUpdate` (routes L631) edits `salesLines` payload key only with sales-tax fields |
| 22 | GET | purchases KPIs (today totals / qty discrepancies) | — | ❌ | No endpoint; index `summary` is all-time status counts with no date or `match=diff` dimension |
| 23 | GET | return orders (مرتجعات) for accountant | — | ❌ | Legacy `return_orders` + models exist (Modules/Purchase) but zero Modules/Admin routes read them |
| 24 | GET | `/operations?source=` (order-source field/filter) | — | ❌ | Source never normalized/exposed; payload hints inconsistent (`origin:'procurement'` vs `kind:'branch_request'`) |

Context (not ACC-3 surface, but the only place real received-qty data exists): `company/me/procurement/purchase-orders*`
(routes L738–748, `ProcurementPurchaseOrderController`) bridges the legacy mobile `purchase_orders` pipeline — **procurement role
only**; exposes `quantityOrdered/quantityConfirmed` but not `quantity_received`, receipts, invoices, or returns.

## 2. Answers to critical checks

1. **Dedicated توثيق (document) action for purchase operations — exists anywhere?** **No (❌).** The only "verify" primitives are per-attachment `POST /attachments/{id}/verify` (routes L294 → `Shared/UploadController.php:109–117`, stamps `verified_at/verified_by_id` on one file) and expense-invoice verify (routes L636–637 → `AccountantCompanyController::verifyExpense`). There is no `operations/{id}/document` route, no `documented*` column in `asab_operations` (`Operation.php:29–36` fillable), and no payload writer for a documentation flag. The meeting flow (accountant documents the order → goes to head) has no backend expression.
2. **Edit line items (price/qty) endpoint for purchase ops — exists?** **No (❌).** `PATCH /company/me/operations/{id}/sales-lines/{rowId}` (`AccountantController::salesLineUpdate`, AccountantController.php:193–238) only mutates the `salesLines`/`sales_lines` payload keys with `amountBeforeTaxHalalas/vatHalalas/amountAfterTaxHalalas` — 404s on a purchases op (no such key). `PATCH /company/me/procurement/orders/{id}` (`ProcurementCompanyController::updateOrder`, lines 52–78) can replace `items` wholesale but is procurement-role only (routes L728), has no per-line qty/price validation, does not recompute `amount`/`match`, and writes no ApprovalStep.
3. **Return orders (مرتجعات) surfaced?** **No on the dashboard (❌).** The legacy mobile world has the full model: `Modules/Purchase/app/Models/ReturnOrder.php` (+`ReturnOrderItem`, migrations `2025_12_07_000009/10`, escalation fields). Grep for `مرتجع|ReturnOrder` across `Modules/Admin/app` returns nothing — no accountant/head route reads them.
4. **Order source field (supplier/other-branch/purchasing-manager) on purchase ops?** **Not exposed (❌).** ASAB payload writers stamp inconsistent hints: `'origin' => 'procurement'` (`ProcurementCompanyController.php:45`) vs `'kind' => 'branch_request'` (`BranchCompanyController.php:220`); neither is mapped by any presenter, and no supplier-originated kind exists. The legacy `purchase_orders.order_type` enum carries exactly the needed taxonomy (`direct_supplier`, `via_purchasing_officer`, `internal_transfer`, `multiple_sources`, `transfer_received` — `Modules/Purchase/app/Enums/OrderType.php:7–11`) but is surfaced only on the procurement bridge (`ProcurementPurchaseOrderController::present` → `orderType`), never to the accountant.
5. **Approved-suppliers read endpoint — does `company/me/suppliers` cover it?** **Mostly (🟡).** Routes L752–754 grant accountant access; `ProcurementController::suppliers` (ProcurementController.php:189–221) is tenant-scoped (`AsabSupplier` uses `BelongsToTenant`), paginated, filterable (search/category/status) and returns `rating` (0–50 scale) + `isActive`. Missing for the SRS supplier cards («الموردون المعتمدون»: items, ★rating, monthly order count): **items count** and **monthly order count** — both computable (`asab_supplier_items.supplier_id`, `Operation module=purchases payload->supplierId`; the export already counts orders at ExportService.php:499–501). Do NOT use `/lookups/suppliers` (L275) — it reads the legacy supplier table.

Bonus finding: the **3-way match itself has no data source** on the accountant surface. Seeded/created purchases payloads carry no per-line ordered/received/unit-price (`AsabOperationSeeder.php:31` seeds only `match='diff'` + `diff_note='فرق في الكمية: 5 كجم'`); real received quantities live in legacy `purchase_order_items.quantity_ordered/quantity_confirmed/quantity_received` and `goods_receipt_items` (`quantity_variance`, `expected_total`, `received_total`, `variance_amount`) with no bridge to ASAB operations.

## 3. Gaps

| # | SRS ref | Missing | Why it matters |
|---|---|---|---|
| G1 | ACC-3.3 | Canonical `purchaseItems` line shape (`item, unit, ordQty, rcvQty, unitPrice, total`) — payload writers store `{itemId,qty}` only; no received qty anywhere in ASAB; legacy receipts unbridged | The core screen (3-way match table الصنف/المطلوب/المستلم/الفرق/سعر الوحدة/الإجمالي + summary tiles + mismatch banner) cannot render at all |
| G2 | ACC-3.4 | توثيق endpoint (accountant documents the order before head approval) | Meeting-mandated step in the approval chain; without it head sees undocumented orders |
| G3 | ACC-3.4 | Accountant edit of line items (price/qty) with amount/match recompute + audit | Accountant must fix supplier-invoice discrepancies before the op reaches head |
| G4 | ACC-3.4 | Return orders (مرتجعات) read surface | SRS explicitly includes returns in the purchases screen; data exists in legacy tables only |
| G5 | ACC-3.4 | Order source normalization + filter (supplier / فرع آخر / مدير المشتريات) | 3 sources are a stated meeting requirement; payload hints exist but are inconsistent and unexposed |
| G6 | ACC-3.1 | Purchases KPI summary: total purchases today · pending review · qty discrepancies · approved | KPI header of the screen; current summary is all-time status counts only |
| G7 | ACC-3.2 | PO row fields (`supplierName`, `receiveDate`, `itemCount`, `receivedTotal`, diff badge) + `supplierId`/date filters on the list | Table columns and filters of the main tab «بيانات المشتريات» |
| G8 | ACC-3.2 | Supplier cards fields `itemsCount` + `monthlyOrderCount` on `company/me/suppliers` | Tab «الموردون المعتمدون» cards are incomplete |
| G9 | ACC-3.3 | Detail export lacks match columns; attachments not embedded in detail response | Modal shows attachments + match table; FE currently must call nothing (no attachments-by-owner endpoint) |

## 4. Tasks (ordered, dependency-aware)

- [ ] **T06.1 Normalize the purchases payload to a canonical `purchaseItems` shape** — Files: `Modules/Admin/app/Services/PurchasePresenterService.php` (new), `Modules/Admin/app/Http/Controllers/Company/ProcurementCompanyController.php` (storeOrder/updateOrder), `Modules/Admin/app/Http/Controllers/Company/BranchCompanyController.php` (storePurchaseRequest) — Accept: every purchases op (new or legacy-shaped payload `items`/`item+qty`) presents `payload.purchaseItems[] = {rowId, item, unit, ordQty, rcvQty|null, unitPriceHalalas, totalHalalas, diffQty}`; writers store the canonical keys; existing payloads read through a tolerant mapper (no migration of old rows required).
- [ ] **T06.2 Purchases detail presenter (3-way match) on `GET /operations/{id}`** — Files: `Modules/Admin/app/Http/Controllers/Operations/OperationController.php` (show), `PurchasePresenterService` — Accept: for `module_key=purchases` the response adds `supplierName` (AsabSupplier lookup), `purchaseItems` table (per T06.1), `summary: {orderedValueHalalas, receivedValueHalalas, qtyDiff, isMatched}`, `mismatch` banner flag when any `diffQty ≠ 0`, and `attachments[]` (Attachment `owner_type='operation'`, `owner_id=op.id`); non-purchases ops unchanged; N+1-free (single supplier + single attachments query).
- [ ] **T06.3 Legacy receiving bridge (received qty)** — Files: `PurchasePresenterService`, `Modules/Admin/app/Services/TenantBranchResolver.php` (reuse `legacyBranchIds`) — Accept: when a purchases op links a legacy PO (`source_module='purchase'`/`source_id`, or payload `legacyOrderId`), `rcvQty` per line is hydrated from `purchase_order_items.quantity_received` (fallback `goods_receipt_items`), tenant-guarded by legacy branch ids; ops without a legacy link keep `rcvQty=null` and `isMatched` computed from ordQty only.
- [ ] **T06.4 توثيق (document) action** — Files: `Modules/Admin/routes/api.php` (POST `company/me/operations/{id}/document` + platform alias `operations/{id}/document`, `asab.role:accountant,head`), `Modules/Admin/app/Http/Controllers/Accountant/AccountantController.php`, `Modules/Admin/app/Services/OperationService.php` — Accept: sets `payload.documentation = {documentedAt, documentedById, note?}`, writes ApprovalStep (stage `review`, action `وثّق المحاسب مستندات الطلب`), 409 `OP_ALREADY_FINAL` on final-approved, idempotent re-document updates timestamp; list/detail presenters expose `isDocumented`; DB::transaction around update+step.
- [ ] **T06.5 Purchase line edit (price/qty)** — Files: routes (PATCH `company/me/operations/{id}/purchase-lines/{rowId}`, `asab.role:accountant,head`), `AccountantController` (mirror `salesLineUpdate` for the `purchaseItems` key) — Accept: body `{ordQty?, rcvQty?, unitPriceHalalas?}` all `numeric|min:0`; recomputes line `totalHalalas`, op `amount`, and `match` (`exact` when all `diffQty=0`, else `diff` + regenerated `diff_note`); 404 unknown rowId, 409 on final-approved; ApprovalStep records old→new values; wrapped in DB::transaction.
- [ ] **T06.6 Order source normalization + filter** — Files: `PurchasePresenterService`, `OperationController@index`, payload writers from T06.1 — Accept: presenter returns `orderSource: {key, labelAr}` ∈ `supplier=«مورد»`, `branch=«فرع آخر»`, `procurement=«مدير المشتريات»`, mapped from payload (`kind='branch_request'→branch`, `origin='procurement'→procurement`, supplier-linked legacy `order_type`: `direct_supplier→supplier`, `internal_transfer|transfer_received→branch`, `via_purchasing_officer|multiple_sources→procurement`); `?source=` filter on the operations index (purchases only); unknown → `procurement` default documented.
- [ ] **T06.7 Purchases KPI block** — Files: `OperationController@index` (extend `summary` when `moduleKey=purchases`) — Accept: summary adds `{todayTotalHalalas, pendingReview, qtyDiscrepancies (status-open ∧ match='diff'), approvedToday}` computed with indexed queries (`operation_date` + `module_key`/`status`/`match` composite index migration if EXPLAIN shows a scan); values verified against seeded fixtures.
- [ ] **T06.8 PO row fields + list filters** — Files: `OperationController@index/present`, `AccountantController@operations` — Accept: purchases rows include `supplierId, supplierName, receiveDate (payload.deliveryDate), itemCount, receivedTotalHalalas, hasDiff`; list accepts `supplierId` (payload->supplierId), `dateFrom`, `dateTo`; supplier names resolved in one `whereIn` query (no N+1).
- [ ] **T06.9 Return orders (مرتجعات) read surface** — Files: routes (GET `company/me/purchases/returns`, `asab.role:accountant,head`), new `Modules/Admin/app/Http/Controllers/Accountant/PurchaseReturnController.php` — Accept: paginated legacy `return_orders` scoped by `TenantBranchResolver::legacyBranchIds` (fail-closed on null branch set for non-admin), rows `{returnNumber, orderNumber, supplierName, branchName, returnDate, status+labelAr, totalReturnAmount, refundAmount, itemCount}`; filters `status`, `branchId`, date range; read-only v1.
- [ ] **T06.10 Approved-suppliers card fields** — Files: `Procurement/ProcurementController@suppliers` — Accept: each supplier row adds `itemsCount` (`asab_supplier_items` grouped count) and `monthlyOrderCount` (purchases ops last 30 days grouped by `payload->supplierId`) — both via 2 grouped queries over the page's ids, not per-row; export stays consistent.
- [ ] **T06.11 Pest feature tests for all of the above** — Files: `tests/Feature/Asab/PurchasesAccountantTest.php` (new) — Accept: suite in §5 green with `-d memory_limit=1024M`; SQLite-safe (no MySQL-only JSON ops without driver guards).
- [ ] **T06.12 Write FE wiring doc from `docs/fe-wiring/_TEMPLATE.md` covering all ✅ endpoints** (+ the ones shipped by T06.2–T06.10) — Files: `docs/fe-wiring/FE-T06-purchases-accountant.md` — Accept: per-endpoint method/path/roles/params/body/response JSON from seeded data, enums with Arabic labels, screen mapping to ACC-3 prototype; master-plan board flipped.

## Delivery notes (what shipped vs the plan)

| Task | Delivered | Deviation / note |
|---|---|---|
| T06.1 | `PurchasePresenterService` + `PurchaseEnums`; canonical `purchaseItems[]` with tolerant mapper for all 3 payload shapes | line carries **two price legs** (`orderedUnitPriceHalalas` vs `unitPriceHalalas`) beyond the plan's single `unitPrice`, reserving the invoice leg |
| T06.2/6.3 | detail `purchases` block on `GET /operations/{id}`; `PurchaseReceivingBridge` hydrates `rcvQty` from `purchase_order_items.quantity_received`, tenant-guarded by `legacyBranchIds` (fail-closed) | non-linked ops keep `rcvQty=null` → `pending`, computed from ordered qty alone |
| T06.4 | `POST /operations/{id}/document` (+company twin) → `OperationService::document`; idempotent, ApprovalStep `review`, 409 on locked | mirrors `requestClarification`; `recordStep` exposed on `OperationService` |
| T06.5 | `PATCH …/purchase-lines/{rowId}` recomputes line total + op `amount` + `match`/`diffNote` via presenter; audit step old→new | **price leg counts in match** (E6) — deviates from the literal "match from diffQty only" |
| T06.6 | `orderSource` derived (payload `kind`/`origin`, legacy `order_type` map, supplier fallback); `?source=` page filter | documented as page-filter (derived, not a column) |
| T06.7 | purchases KPI block in list `meta.summary.purchases` (`todayTotalHalalas`/`pendingReview`/`qtyDiscrepancies`/`approvedToday`) | `todayTotalHalalas` = every order dated today (any status), not only approved |
| T06.8 | row `purchaseRow{supplierName,receiveDate,itemCount,orderedTotal,receivedTotal,hasDiff,isDocumented}`; `supplierId`/date filters; supplier names in one `whereIn` | — |
| T06.9 | `GET /purchases/returns` (+company twin) `PurchaseReturnController`, paginated, legacy-branch-scoped fail-closed, Arabic status labels | read-only v1; `supplierName` best-effort (legacy supplier ids) |
| T06.10 | supplier cards `itemsCount` + `monthlyOrderCount` via 2 grouped queries; monthly tally grouped in PHP off decoded payload (driver-safe JSON) | avoids `json_extract` quoting mismatch across SQLite/MySQL |
| lookups | `GET /lookups/purchase-enums` added | orderSource + lineMatch + returnStatus catalog |

## 5. Tests required

Pest feature tests (SQLite in-memory; seed one tenant + one foreign tenant):

1. `GET /operations?moduleKey=purchases` returns only purchases ops of the caller's tenant/branch scope; foreign-tenant op invisible (tenant isolation).
2. Purchases list rows expose `supplierName/receiveDate/itemCount/hasDiff`; `?supplierId=`, `?dateFrom/dateTo`, `?source=branch` filters narrow correctly.
3. KPI summary: op dated today vs yesterday → `todayTotalHalalas`/`approvedToday` count only today; `match='diff'` pending op increments `qtyDiscrepancies`.
4. `GET /operations/{id}` (purchases) returns `purchaseItems` with computed `diffQty`, `summary` tiles, `mismatch=true` when ordQty≠rcvQty, attachments array.
5. Legacy bridge: purchases op linked to a legacy PO with `quantity_received` set → detail shows that `rcvQty`; legacy PO of another company's branch → not hydrated (404/null).
6. توثيق: accountant documents → `isDocumented=true` + ApprovalStep row; second call idempotent; on final-approved op → 409 `OP_ALREADY_FINAL`; branch-manager role → 403.
7. Line edit: PATCH purchase-lines updates qty/price, recomputes op `amount` + `match` flips exact↔diff; unknown rowId → 404; final-approved → 409; role `branch` → 403; audit step records old→new.
8. Reject requires `reason` (422 without); rejected op notifies submitter; reject on final → 409.
9. Bulk-approve: mixed batch (ok + already-approved + foreign-tenant id) → foreign id dropped/failed, no partial corruption.
10. Returns list: legacy return_order in tenant branch appears with Arabic status label; foreign-branch return hidden; procurement role → 403 (accountant/head only).
11. Suppliers tab: `GET /company/me/suppliers` as accountant → 200 with `itemsCount`/`monthlyOrderCount`; foreign tenant's supplier absent; as cashier-less role (e.g. no role) → 403.
12. Export: `GET /operations/export?moduleKey=purchases&format=xlsx` streams file with purchases headings (smoke).

## 6. FE wiring notes

- **Canonical paths:** company SPA uses `/company/me/operations*` for list/approve/bulk/notes/export, but **detail, reject, audit-trail and correction have no `/company/me` alias** — call the shared `/api/v1/operations/{id}`, `/operations/{id}/reject`, `/operations/{id}/audit-trail`, `/operations/{id}/correction` (same auth/tenant middleware). Document this split explicitly.
- **Suppliers:** canonical read is `GET /company/me/suppliers`; `/company/me/procurement/suppliers` is a procurement-SPA alias of the same handler. **Never** use `/lookups/suppliers` for the approved tab (legacy store, id+name only).
- **Enums (key + Arabic label):** status `pending=قيد المراجعة، approved=معتمد (أُرسل لرئيس الحسابات)، final-approved=معتمد نهائياً، rejected=مرفوض`; match `exact/diff/review` (diff badge = فرق كمية); urgency `normal=عادي، urgent=عاجل`; orderSource `supplier=مورد، branch=فرع آخر، procurement=مدير المشتريات`; returns statuses from legacy enum need labelAr mapping in T06.9.
- **Tabs:** «بيانات المشتريات» = operations list (moduleKey=purchases); «الموردون المعتمدون» = `company/me/suppliers` cards (items, ★rating, monthly orders). Rating arrives on a 0–50 scale (stars×10) — FE divides by 10.
- **Money:** all amounts are integer halalas (`amount`, `unitPriceHalalas`, `totalHalalas`); exports already render ر.س.
- **Actions row (ACC-3.4):** approve → `POST .../approve`; «رفض مع ذكر السبب» → `POST /operations/{id}/reject` with required `reason` (max 500); close → head-only `POST /operations/{id}/final-approve`; توثيق → new `POST .../document` (T06.4); line edit → new `PATCH .../purchase-lines/{rowId}` (T06.5).
- **Idempotency:** all `company/me/*` mutations pass an idempotency key (`asab.idempotency` middleware); shared `/operations/*` mutations do not require it but are safe to retry only for document (idempotent) — approve/reject return 409 on repeat.
- **Attachments:** per-file verify is `POST /attachments/{id}/verify` — distinct from the order-level توثيق; the detail modal shows both (file badges vs order badge `isDocumented`).
- **Pagination contract:** `page`/`pageSize` (max 100), envelope `{success, message, data, meta:{page,pageSize,total,totalPages,summary}}`.

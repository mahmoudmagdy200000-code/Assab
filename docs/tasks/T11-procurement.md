# T11 — Procurement
> SRS: §9 PRC-1..3 (KPIs incl monthly savings, item-level consolidation groups with per-branch qty lines + suggested supplier + savings calc, status chain new→grouped→sent→confirmed, grouped-by-supplier view, sent orders ETA, partial approve/reject with reason, own items & suppliers CRUD, price history, mobile PO pipeline) · Audited: 2026-07-10 · FE doc deliverable: docs/fe-wiring/FE-T11-procurement.md
> Status: ⬜ not started (audit complete)

## 0. Architecture context (read before grading claims)

Two order pipelines coexist (docs/PROCUREMENT_DASHBOARD_API.md §0):

- **Bridge family** `.../procurement/purchase-orders*` → legacy mobile `purchase_orders`
  (`Modules/Purchase`). Handler `ProcurementPurchaseOrderController` + `ProcurementDecisionService`
  + `OrderConsolidationService`. Amounts = SAR floats. **This is the real branch→procurement loop.**
- **Operations family** `.../procurement/orders*` → `asab_operations` (dashboard-created manual
  orders). Handler `ProcurementController` + `ProcurementCompanyController`. Amounts = integer halalas.

Tenancy: `Operation`/`AsabSupplier` carry the `BelongsToTenant` global scope
(Modules/Admin/app/Models/Concerns/BelongsToTenant.php:17); bridge queries scope through
`TenantBranchResolver::legacyBranchIds()` (Modules/Admin/app/Services/TenantBranchResolver.php:29).
Both surfaces run under `asab.tenant` (routes/api.php:244 platform, :598 company) — no leak found.

## 1. Endpoint inventory (audited against code)

Handlers: `PC` = Modules/Admin/app/Http/Controllers/Procurement/ProcurementController.php ·
`PPO` = .../Procurement/ProcurementPurchaseOrderController.php ·
`PCC` = Modules/Admin/app/Http/Controllers/Company/ProcurementCompanyController.php ·
`EXP` = .../Company/ExportController.php. Route refs = Modules/Admin/routes/api.php.

### 1a. Platform surface `/api/v1/procurement/*` (`asab.tenant` + `asab.role:procurement`)

| # | Method | Path | Handler | Status | Notes |
|---|---|---|---|---|---|
| 1 | GET | procurement/overview | PC::overview (L21) | 🟡 | r425. KPIs = newOrders/consolidated/sentToSuppliers/ordersValueThisWeek over Operations only. Missing PRC-1.1 monthly-savings KPI (SAR+%+trend); doesn't count bridge pipeline (FE workaround documented in doc §2.1); urgency derived from dead `match==='diff'` |
| 2 | GET | procurement/orders | PC::orders (L39) | 🟡 | r426. Paginated + status filter, tenant-scoped. `urgency` presented from `match==='diff'` (PC L283) but OperationFactory always writes `match='exact'` (OperationFactory.php:37) → عاجل never renders; `payload.urgency` ignored |
| 3 | GET | procurement/orders/{id} | PC::show (L53) | 🟡 | r427. Returns row + raw payload; same dead urgency field |
| 4 | POST | procurement/orders/{id}/approve | PC::approve (L64) | ✅ | r428. OperationService::approve, 409 OP_NOT_PENDING |
| 5 | POST | procurement/orders/{id}/reject | PC::reject (L71) | ✅ | r429. reason required (max 500), 409 OP_ALREADY_FINAL |
| 6 | POST | procurement/orders/{id}/partial-reject | PC::partialReject (L80) | 🟡 | r430. Writes `payload.partialReject` + ad-hoc status `partial_reject` (not in Operation STATUS_ constants); no validation that rejectedItemIds ⊆ payload items; no ApprovalStep audit entry |
| 7 | POST | procurement/orders/consolidate | PC::consolidate (L147) | 🟡 | r431. Stamps payload.consolidatedGroupId + status=approved. No status guard (re-consolidates final ops); returns `orderCount = count(input ids)` even when 0 matched; no item grouping/savings |
| 8 | POST | procurement/orders/{groupId}/send | PC::send (L166) | 🟡 | r432. Flips to final-approved + payload.sentAt. 200 `{sent:0}` for unknown group; no PO-BATCH id, no ETA, no supplier notification |
| 9 | GET | procurement/suppliers | PC::suppliers (L189) | 🟡 | r433. Paginated, search/category/status, tenant-scoped (BelongsToTenant). Missing PRC-3.2 row fields lifetime orders/spend and the KPIs (active suppliers/total purchases/avg rating) |
| 10 | GET | procurement/items | PC::items (L228) | 🟡 | r434. Paginated, company-filtered, search/category/supplierId. Missing PRC-3.1 supplier COUNT per item + brand; consumption/stock columns absent (doc'd gap §2.5) |
| 11 | GET | procurement/purchase-orders/approved-by-me | PPO::approvedByMe (L77) | ✅ | r438. `decidedBy` scope (PurchaseOrder.php:331), paginated |
| 12 | POST | procurement/purchase-orders/bulk-approve | PPO::bulkApprove (L129) | ✅ | r439. «اعتماد الكل»; per-order failures collected; out-of-tenant ids reported not-found |
| 13 | GET | procurement/purchase-orders/grouped | PPO::grouped (L187) | ✅ | r440. by=supplier (default) / city; per-item qty aggregation + supplier stock capacity warnings (OrderConsolidationService::previewBySupplier L44). **No `by=item` — PRC-2.1 gap tracked in §3** |
| 14 | POST | procurement/purchase-orders/grouped/send | PPO::sendGroup (L202) | ✅ | r441. Creates PurchaseOrderGroup `GRP-YYYYMMDD-XXXX`, 409 CONSOLIDATION_FAILED on mismatch/empty, lockForUpdate txn |
| 15 | GET | procurement/purchase-orders/sent | PPO::sent (L230) | 🟡 | r442. Groups w/ derived live status + sentAt (max 100). **No ETA field** (PRC-2.5); group ids are GRP- not PO-BATCH- (cosmetic) |
| 16 | GET | procurement/purchase-orders/groups/{groupId} | PPO::groupShow (L238) | ✅ | r443. Batch tracking details: header + aggregated items + member orders |
| 17 | GET | procurement/purchase-orders | PPO::index (L45) | ✅ | r444. status=incoming(pending+emergency+variance)/exact, branchId, priority filters; validated; paginated; tenant-scoped |
| 18 | GET | procurement/purchase-orders/{id} | PPO::show (L92) | ✅ | r445. id or orderNumber; items + consumption context (بيانات الاستهلاك) |
| 19 | POST | procurement/purchase-orders/{id}/approve | PPO::approve (L118) | ✅ | r446. ProcurementDecisionService::approve — confirms pending lines, → confirmed, 409 ORDER_NOT_DECIDABLE |
| 20 | POST | procurement/purchase-orders/{id}/partial-approve | PPO::partialApprove (L157) | ✅ | r447. Per-line quantities, 0 = reject line, all-zero = full reject, note → rejection_reason (PRC-2.6) |
| 21 | POST | procurement/purchase-orders/{id}/reject | PPO::reject (L177) | ✅ | r448. reason required |

### 1b. Company surface `/api/v1/company/me/procurement/*` (`asab.tenant`+`asab.idempotency`+`asab.audit`+`asab.role:procurement`)

| # | Method | Path | Handler | Status | Notes |
|---|---|---|---|---|---|
| 22 | GET | overview | PC::overview | 🟡 | r716. Same as #1 |
| 23 | GET | orders/grouped | PCC::grouped (L89) | 🟡 | r717. Groups PENDING operations by payload.supplierId (branch chips, totals, orderIds) — supplier-grouping preview, but unbounded `get()` (no pagination/cap) and pre-consolidation semantics (SRS "grouped" = post-consolidation) |
| 24 | GET | orders/sent | PCC::sent (L113) | 🟡 | r718. Has sentAt + ETA (payload.deliveryDate) + inTransit + diffForHumans labels — best PRC-2.5 match in Operations family; unbounded `get()` |
| 25 | GET | orders | PC::orders | 🟡 | r719. Same as #2 |
| 26 | POST | orders | PCC::storeOrder (L28) | 🟡 | r720. PRC-1.2 create modal. `brandId` validated then **dropped** (never persisted); `items.*.unitPriceHalalas/totalHalalas` used for total but unvalidated; origin column stamped 'mobile' by factory (payload.origin='procurement' only) |
| 27 | POST | orders/grouped/{groupId}/send | PC::send | 🟡 | r721. Canonical send path; same defects as #8 |
| 28 | POST | orders/approve | PC::bulkApprove (L113) | ✅ | r723. Bulk by orderIds or branch/supplier filter; atomic DB::transaction |
| 29 | POST | orders/{id}/partial-reject | PC::partialReject | 🟡 | r724. Same as #6 |
| 30 | POST | grouped/{groupId}/send | PC::send | 🟡 | r725. Doc-conformance alias of #27 |
| 31 | POST | orders/{id}/approve | PC::approve | ✅ | r726 |
| 32 | POST | orders/{id}/reject | PC::reject | ✅ | r727 |
| 33 | PATCH | orders/{id} | PCC::updateOrder (L52) | 🟡 | r728. Accepts raw `status` (pending/approved/rejected/final-approved) written directly — bypasses OperationService pipeline: no ApprovalStep, no actor stamps, procurement can self-set final-approved |
| 34 | DELETE | orders/{id} | PCC::destroyOrder (L80) | 🟡 | r729. Soft-deletes without status guard — final-approved (locked 🔒) operations deletable, violates immutability rule |
| 35 | GET | items/export | EXP::procurementItemsExport (L106) | ✅ | r730. ExportService::procurementItems (ExportService.php:518), xlsx/csv |
| 36 | GET | items/{id}/price-history | PCC::priceHistory (L205) | 🟡 | r731. Works, ordered desc; but supplierId/supplierName always null — writes (PCC L159, L178) never populate them, so price comparison per supplier is impossible |
| 37 | GET | items | PC::items | 🟡 | r732. Same as #10 |
| 38 | POST | items | PCC::storeItem (L140) | ✅ | r733. Validated; seeds price-history; write-through to mobile catalog (ProcurementCatalogBridgeService::syncItem); txn |
| 39 | PATCH | items/{id} | PCC::updateItem (L171) | ✅ | r734. Price change auto-appends price-history; bridge sync; txn |
| 40 | DELETE | items/{id} | PCC::destroyItem (L191) | ✅ | r735. Deactivates bridged mobile rows then soft-deletes; txn |
| 41 | GET | purchase-orders/approved-by-me | PPO::approvedByMe | ✅ | r738. = #11 |
| 42 | POST | purchase-orders/bulk-approve | PPO::bulkApprove | ✅ | r739. = #12 |
| 43 | GET | purchase-orders/grouped | PPO::grouped | ✅ | r740. = #13 |
| 44 | POST | purchase-orders/grouped/send | PPO::sendGroup | ✅ | r741. = #14 |
| 45 | GET | purchase-orders/sent | PPO::sent | 🟡 | r742. = #15 (no ETA) |
| 46 | GET | purchase-orders/groups/{groupId} | PPO::groupShow | ✅ | r743. = #16 |
| 47 | GET | purchase-orders | PPO::index | ✅ | r744. = #17 |
| 48 | GET | purchase-orders/{id} | PPO::show | ✅ | r745. = #18 |
| 49 | POST | purchase-orders/{id}/approve | PPO::approve | ✅ | r746. = #19 |
| 50 | POST | purchase-orders/{id}/partial-approve | PPO::partialApprove | ✅ | r747. = #20 |
| 51 | POST | purchase-orders/{id}/reject | PPO::reject | ✅ | r748. = #21 |

### 1c. Suppliers write/rate `/api/v1/company/me/*`

| # | Method | Path | Handler | Status | Notes |
|---|---|---|---|---|---|
| 52 | GET | suppliers/export | EXP::suppliersExport (L100) | ✅ | r753 (role: all company roles). ExportService::companySuppliers (L496) |
| 53 | GET | suppliers | PC::suppliers | 🟡 | r754. Canonical suppliers list; same PRC-3.2 field gaps as #9 |
| 54 | GET | procurement/suppliers | PC::suppliers | 🟡 | r756. Alias of #53 (SPA prefix) |
| 55 | GET | procurement/suppliers/export | EXP::suppliersExport | ✅ | r757. Alias of #52 |
| 56 | POST | suppliers | PCC::storeSupplier (L216) | ✅ | r760 (role: procurement,company-admin). Validated (+phone/email aliases); provisions login-capable mobile supplier via bridge; txn |
| 57 | PATCH | suppliers/{id} | PCC::updateSupplier (L245) | ✅ | r761. Company-scoped findOrFail; bridge syncSupplier |
| 58 | POST | suppliers/{id}/toggle-active | PCC::toggleSupplier (L266) | ✅ | r762. active↔inactive + bridge syncSupplierActive |
| 59 | POST | procurement/suppliers | PCC::storeSupplier | ✅ | r765. Alias of #56 |
| 60 | PATCH | procurement/suppliers/{id} | PCC::updateSupplier | ✅ | r766. Alias of #57 |
| 61 | POST | procurement/suppliers/{id}/toggle-active | PCC::toggleSupplier | ✅ | r767. Alias of #58 |
| 62 | POST | suppliers/{id}/ratings | PCC::rateSupplier (L280) | ✅ | r770 (role: procurement,branch). rating 1..5 + comment; recomputes avg (0–50 = stars×10); txn |
| 63 | POST | procurement/suppliers/{id}/ratings | PCC::rateSupplier | ✅ | r771. Alias of #62 |

### 1d. Reports (PRC-4 — `DEFERRED` per meeting; placeholders only)

| # | Method | Path | Handler | Status | Notes |
|---|---|---|---|---|---|
| 64 | GET | procurement/reports | ReportController::catalog | ✅ | r794. Catalog placeholder — product-deferred, no build work |
| 65 | GET | procurement/reports/{key}/download | CrossController::reportDownload | ✅ | r795. Same |

### 1e. Required by SRS, no endpoint (❌)

| Requirement | Status | Notes |
|---|---|---|
| PRC-2.1 item-level consolidation groups (per-branch qty lines, suggested supplier, unit price, total cost, savings SAR+%) | ❌ | No `by=item` grouping anywhere; no suggested-supplier logic; no savings calc on any dashboard endpoint |
| PRC-1.1 monthly savings KPI (SAR + % of purchases + trend) | ❌ | overview has no savings figure; the only savings math in the repo is legacy officer-flow (CalculationService.php:106, OrderDataService.php:534-601), unwired to procurement/* |
| PRC-3.2 supplier KPIs (active suppliers · total purchases · avg rating) + per-row lifetime orders/spend | ❌ | No KPI endpoint/meta; rows lack spend/orders fields |

## 2. Answers to critical checks

1. **Does consolidation group requests BY ITEM across branches with savings calc from supplier prices? — NO.**
   Grouping is by **supplier** (`OrderConsolidationService::previewBySupplier`, Modules/Purchase/app/Services/OrderConsolidationService.php:44) or **city** (:85). Per-item aggregation exists only *inside* a supplier group (`aggregateItems` :225) and compares quantity vs the supplier's declared **stock capacity** (`supplier_products.stock_quantity`, :245-247) — no price comparison, no suggested supplier, no savings. The Operations-family `consolidate` (ProcurementController.php:147) just stamps orderIds with a supplierId. Savings math exists in the repo (CalculationService::calculateSavings, Modules/Purchase/app/Services/CalculationService.php:106; OrderDataService.php:534-601 `total_expected_savings`) but only for the legacy mobile purchasing-officer flow — zero dashboard exposure. **PRC-2.1's core view is missing → build task T11.7.**

2. **Does the status chain match new→grouped→sent→confirmed? — PARTIALLY, with shifted semantics.**
   - Operations family: `pending → approved → final-approved` (+`rejected`, ad-hoc `partial_reject`) used as new→consolidated→sent (ProcurementController.php:158, :176). No `confirmed` state — nothing ever flips a sent operation to confirmed.
   - Bridge family: `pending|emergency|variance → confirmed` where **confirmed = purchasing-manager approval, not supplier confirmation** (ProcurementDecisionService.php:24, :41). There is **no "grouped" holding state**: grouping and sending are one action (`sendGroup` stamps `group_id` + `sent_at` together, OrderConsolidationService.php:134-142). After send the supplier progresses orders `preparing → on_the_way → delivered` (OrderStatus.php:18-21).
   - Actions match PRC-2.2 loosely: new → «تجميع وإرسال للمورد» = grouped/send in one step; a separate «إرسال للمورد» on an already-grouped batch doesn't exist because the intermediate state doesn't.

3. **Does partial-approve exist (meeting: approve/partial/reject with reason)? — YES, on the bridge family.**
   `POST .../purchase-orders/{id}/partial-approve` (ProcurementPurchaseOrderController.php:157) → `ProcurementDecisionService::approvePartial` (:56): per-line approved quantities (capped at ordered), quantity 0 rejects the line with `rejection_reason` = note (e.g. «سعر أعلى من المتفق عليه»), all-zero = full rejection; approve (:32) and reject with mandatory reason (:103) complete the trio; «الطلبات المعتمدة» list = `approved-by-me` (:77). The Operations family only has partial-**reject** (ProcurementController.php:80) with weaker validation and no audit step.

4. **Do branch purchase-requests feed into procurement orders (bridge)? — YES, via two disjoint feeds.**
   (a) Mobile-app orders: procurement reads/decides `purchase_orders` **directly** — same rows the branch/supplier apps use (ProcurementPurchaseOrderController::scoped :256, tenant-scoped via TenantBranchResolver.php:29; decisions via ProcurementDecisionService = single write-path, decision_source `dashboard_procurement` :21).
   (b) Dashboard branch surface: `POST company/me/branch/purchase-requests` (routes/api.php:700 → BranchCompanyController::storePurchaseRequest :206) creates an Operation (module_key `purchases`), pushes a notification to the procurement role («طلب شراء جديد من فرع») and a realtime `purchase_request.new` event (RealtimeBroadcaster.php:278) → appears in `GET .../procurement/orders`. **Quirk:** feed (b) never enters the bridge pipeline — the two queues don't merge (FE must watch both).

5. **Does supplier confirmation flip the group to confirmed? — NO explicit flip.**
   `PurchaseOrderGroup::deriveStatus()` (Modules/Purchase/app/Models/PurchaseOrderGroup.php:53) returns `confirmed` **by default immediately after send** — before any supplier action — and only changes when the supplier moves member orders to `preparing`/`on_the_way`/`delivered`. So the «مؤكد» chip conflates "sent, awaiting supplier" with "supplier confirmed" (PRC-2.2 "sent → awaiting supplier confirmation" is not distinguishable). For Operations-family sent orders there is no supplier feedback at all (ASAB supplier portal is feature-flagged off, routes/api.php:456-459).

## 3. Gaps

1. **PRC-2.1 → item-level consolidation view missing** → The SRS's "core value loop" screen (group card per item: «n طلبات من n فروع», per-branch qty lines, suggested supplier, unit price, total cost, savings SAR+%) has no backend. Grouped views are supplier/city only. Without it the consolidation UI cannot render its primary card.
2. **PRC-1.1 → monthly savings KPI missing** → overview KPIs lack the headline "وفورات الشهر" (SAR + % of purchases + trend); overview also counts only the Operations family, so KPI cards ignore the real mobile pipeline (FE currently told to make a second call for the real count — doc §2.1).
3. **PRC-2.5 → no ETA on bridge sent groups** → `sent`/`groupShow` return derived status + sentAt but no expected-delivery date; the sent-orders table's ETA column has no source (Operations-family `orders/sent` does return `eta` from payload.deliveryDate, but that's the manual-orders world only).
4. **PRC-2.3 → "sent" vs "supplier-confirmed" indistinguishable** → group derives `confirmed` at send time (check 5); the SRS chain's last transition (supplier confirms) can't be shown truthfully.
5. **§5 pipeline integrity → PATCH/DELETE company orders bypass the state machine** → updateOrder writes raw `status` (incl. final-approved) with no OperationService/ApprovalStep; destroyOrder deletes final-approved (locked 🔒) ops. Violates the master-plan immutability rule.
6. **PRC-2.2/2.6 → Operations partial-reject weak** → ad-hoc `partial_reject` status outside the documented vocabulary, no validation of rejectedItemIds against payload items, no audit step; urgency label dead (`match==='diff'` never written → «عاجل» never renders despite payload.urgency='urgent').
7. **PRC-3.1/3.2 → catalog/supplier enrichment missing** → items rows lack supplier count + brand; price-history rows always have null supplierId/supplierName (writes never populate them → «مقارنة الأسعار» per supplier impossible); supplier rows lack lifetime orders/spend; no supplier KPIs.
8. **§5.2b origin → procurement-created orders stamped `origin='mobile'`** → OperationFactory hardcodes 'mobile' (OperationFactory.php:38); storeOrder's payload carries origin='procurement' but the column (the queryable/reported one) is wrong — «🛒 سير المشتريات» origin chip will misreport.
9. **Performance/consistency** → company `orders/grouped` + `orders/sent` return unbounded collections (no pagination/cap — violates memory-cap guardrail); `consolidate` reports orderCount from input, not matched rows; `send` returns 200 for a nonexistent group.

## 4. Tasks (ordered, dependency-aware)

- [ ] **T11.1** Fix Operations urgency presentation: derive from `payload.urgency` (normal|urgent), return `{urgency, urgencyLabel: عادي|عاجل}` key+label pair; stop reading `match==='diff'` — Files: `Modules/Admin/app/Http/Controllers/Procurement/ProcurementController.php` (present, overview) — Accept: op created with urgency=urgent lists `urgencyLabel='عاجل'`; normal lists «عادي»; overview newOrders rows same.
- [ ] **T11.2** Stamp correct operation origin: add `origin` param to `OperationFactory::createFromUpload` (default `mobile`), pass `procurement` from `ProcurementCompanyController::storeOrder` — Files: `Modules/Admin/app/Services/OperationFactory.php`, `Modules/Admin/app/Http/Controllers/Company/ProcurementCompanyController.php` — Accept: POST orders → row has `origin='procurement'` in asab_operations; branch purchase-request still `mobile`.
- [ ] **T11.3** Harden Operations consolidate/send: consolidate → guard matched rows (404/422 if any id unmatched or non-pending), return matched count; send → 404 `NOT_FOUND` when group has 0 ops, stamp a `PO-BATCH-xxx` public batch id in payload — Files: `ProcurementController.php` — Accept: consolidate with 1 bogus id → 422 listing it; send unknown group → 404; response includes batch id.
- [ ] **T11.4** Enforce pipeline on updateOrder/destroyOrder: route status changes through `OperationService` (ApprovalStep + actor stamps), reject `status=final-approved` direct set and any mutation/delete of a final-approved op with 409 `OP_ALREADY_FINAL` — Files: `ProcurementCompanyController.php`, `Modules/Admin/app/Services/OperationService.php` — Accept: PATCH status→final-approved as procurement → 409; DELETE final-approved op → 409; PATCH payload of pending op still 200.
- [ ] **T11.5** Align Operations partial-reject with PRC-2.6: validate `rejectedItemIds` ⊆ payload.items ids (422 otherwise), record an ApprovalStep, keep documented status `partial_reject` + Arabic label «مرفوض جزئياً» in responses — Files: `ProcurementController.php` — Accept: bogus item id → 422; success response carries reason + rejected ids + label; step row exists.
- [ ] **T11.6** Cap/paginate company `orders/grouped` + `orders/sent` (paginatedResponse or explicit `limit(100)` like bridge `sentGroups`) — Files: `ProcurementCompanyController.php` — Accept: >100 matching ops → bounded response with meta.
- [ ] **T11.7** Build item-level consolidation preview (PRC-2.1, the core value loop): `OrderConsolidationService::previewByItem(?array $branchIds)` over consolidatable orders — per catalog item: `{itemId, name, unit, requestsCount, branchLines:[{branchId, branchName, qty, unit}], totalQuantity, suggestedSupplier:{id, name, unitPrice}, unitPrice, totalCost, savings, savingsPct, status}`; suggested supplier = cheapest active `supplier_products`/`SupplierItem` price for the item; savings = (current/entered unit price − suggested price) × totalQuantity, via `CalculationService::calculateSavings`; expose as `GET .../procurement/purchase-orders/grouped?by=item` (both surfaces) — Files: `Modules/Purchase/app/Services/OrderConsolidationService.php`, `ProcurementPurchaseOrderController.php`, `Modules/Admin/routes/api.php` — Accept: 2 branches ordering the same item → one group card with 2 branchLines, suggested supplier = cheapest, savings amount+pct computed; item with no supplier price → savings null, no crash.
- [ ] **T11.8** Persist savings at send + monthly-savings KPI (PRC-1.1): snapshot computed savings on `PurchaseOrderGroup` (migration: `savings_amount`, `savings_pct` nullable) at `sendGroup`; extend `overview` with `monthlySavings: {amountHalalas|amount, pctOfPurchases, trendPct}` (current vs previous month) and bridge counts (`incoming`, `readyToSend`, `sentAwaitingConfirmation`) so KPI cards match PRC-1.1 — Files: `OrderConsolidationService.php`, new migration in `Modules/Purchase/database/migrations/` (guard `DB::getDriverName()` if FK-touching), `ProcurementController.php` — Accept: after a send with savings, overview monthlySavings reflects it; month with no sends → 0 + trend computed; counts equal bridge queries. Depends on T11.7.
- [ ] **T11.9** ETA on sent groups (PRC-2.5): accept optional `expectedDeliveryDate` in `sendGroup` body (persist on group; fallback = min `next_supply_date` of member order items), return `eta` in `sentGroups` + `groupDetails` rows — Files: `OrderConsolidationService.php`, `ProcurementPurchaseOrderController.php`, migration (same file as T11.8) — Accept: send with expectedDeliveryDate → `sent` row carries eta ISO date; without → derived or null.
- [ ] **T11.10** Distinguish sent vs supplier-confirmed (PRC-2.3): `deriveStatus()` returns `sent` («أُرسل للمورد») while all member orders are still manager-`confirmed` with no supplier action, `confirmed` («مؤكد») once the supplier acts (any order ≥ preparing or supplier-side acceptance flag), then preparing/on_the_way/delivered as today — Files: `Modules/Purchase/app/Models/PurchaseOrderGroup.php`, doc update — Accept: freshly sent group → status `sent`; after one order → preparing → status reflects supplier engagement; FE labels documented.
- [ ] **T11.11** Items enrichment (PRC-3.1): add `supplierCount` (distinct suppliers offering the item across `supplier_products`/price history) and `brandId`/`brandName` to items rows; persist `brandId` on storeItem/updateItem if provided — Files: `ProcurementController.php` (items), `ProcurementCompanyController.php`, migration for `asab_supplier_items.brand_id` if absent — Accept: item priced by 2 suppliers → supplierCount=2; created with brandId → returned.
- [ ] **T11.12** Price-history supplier attribution: populate `supplier_id`/`supplier_name` on every `ProcurementItemPrice` write (from the item's supplier at write time or request field) — Files: `ProcurementCompanyController.php` (storeItem L159, updateItem L178) — Accept: price-history rows for a new item show non-null supplierId/supplierName; legacy null rows still render.
- [ ] **T11.13** Suppliers enrichment (PRC-3.2): add per-row `lifetimeOrdersCount` + `lifetimeSpend` (aggregate from bridge purchase_orders by supplier_id + Operations payload.supplierId) and a KPIs block (meta or `GET .../suppliers/kpis`): `{activeSuppliers, totalPurchases, avgRating}` — Files: `ProcurementController.php` (suppliers), possibly a small query service — Accept: supplier with 3 delivered orders shows count/spend; KPIs match seeded data; no N+1 (single aggregate query).
- [ ] **T11.14** storeOrder fixes (PRC-1.2): persist `brandId` into payload; validate `items.*.unitPriceHalalas`/`items.*.totalHalalas` as integer≥0 — Files: `ProcurementCompanyController.php` — Accept: POST with brandId → payload.brandId set; negative price → 422.
- [ ] **T11.15** Write FE wiring doc `docs/fe-wiring/FE-T11-procurement.md` from `_TEMPLATE.md` covering all ✅ endpoints (+ the ones fixed above), superseding/absorbing `docs/PROCUREMENT_DASHBOARD_API.md` content per the FE-doc protocol (real JSON from seeded data, enums + Arabic labels, screen mapping, alias table) — Accept: doc exists, master-plan board flipped.

## 5. Tests required

Pest feature tests (SQLite in-memory; run with `-d memory_limit=1024M`):

**Bridge pipeline (`tests/Feature/ProcurementPurchaseOrderTest.php` — extend if exists)**
1. incoming list returns pending+emergency+variance only; draft/internal-transfer orders never visible.
2. approve → status confirmed, lines confirmed at ordered qty, decided_by/decision_source stamped; second approve → 409 ORDER_NOT_DECIDABLE.
3. partial-approve: mixed quantities (cap at ordered, 0 rejects line w/ note as rejection_reason); all-zero → full rejection; foreign orderItemId → 409.
4. reject requires reason (422 without); bulk-approve collects per-order failures + not-found ids.
5. grouped?by=supplier: aggregation counts, capacity exceeded flag when qty > declared stock, capacity null when undeclared; by=item (T11.7): branch lines + suggested supplier + savings; item without supplier price → null savings.
6. grouped/send: creates group + stamps group_id; orderIds subset honored; supplier-mismatch and empty-set → 409 CONSOLIDATION_FAILED; sent groups disappear from preview.
7. sent + groups/{id}: derived status transitions (sent → confirmed → preparing → on_the_way → delivered per T11.10); eta present (T11.9).
8. **Tenant isolation:** manager of company A gets 404 on company B's order id (index/show/approve/groupShow); branch-scoped role (`scope!=all`) only sees its branch ids.
9. **Role denial:** accountant/branch/head token on any `/procurement/purchase-orders*` route → 403 WRONG_ROLE.

**Operations family (`tests/Feature/ProcurementOperationsTest.php`)**
10. overview KPIs match seeded counts; monthlySavings block correct incl. zero-month (T11.8); urgency labels عاجل/عادي correct (T11.1).
11. storeOrder: 201, halalas total computed; brandId persisted (T11.14); origin column = procurement (T11.2); idempotency-key replay returns stored response.
12. consolidate: happy path stamps group + approved; bogus id → 422 (T11.3); send unknown group → 404; sent list shows eta/inTransit; bounded (T11.6).
13. partial-reject: valid ids → status partial_reject + payload.reason; id not in payload.items → 422 (T11.5).
14. updateOrder cannot set final-approved directly (409); destroyOrder on final-approved → 409; pending edit/delete OK (T11.4).
15. bulk `orders/approve` by branch filter approves only that branch's pending ops, atomically (inject failure → nothing committed).

**Items & suppliers (`tests/Feature/ProcurementCatalogTest.php`)**
16. items CRUD: create seeds price-history + bridged mobile row; price update appends history with supplier attribution (T11.12); delete deactivates bridge row; supplierCount correct (T11.11).
17. price-history ordered desc, company-scoped (company B's item id → 404).
18. suppliers CRUD + toggle + rate: avg rating 0–50 recompute; rating 6 → 422; branch role can rate but cannot create (403); lifetime counts/KPIs (T11.13).
19. suppliers/items exports return binary with correct headers; role matrix (all company roles read suppliers export; only procurement/company-admin write).
20. **Alias parity:** `/company/me/procurement/suppliers` ≡ `/company/me/suppliers` (same payload); `grouped/{groupId}/send` ≡ `orders/grouped/{groupId}/send`.
21. **Branch feed:** branch storePurchaseRequest → notification to procurement role + op visible in procurement orders list (feeds check 4b).

## 6. FE wiring notes

- **Two pipelines — never mix formatters or screens** (doc §0): bridge `purchase-orders*` = real branch/mobile orders, **SAR floats**; Operations `orders*` + items/suppliers = manual dashboard orders, **integer halalas** (divide by 100). Wire الطلبات الجديدة/المجمعة/المرسلة to the bridge family.
- **Canonical paths vs aliases** (FE must call canonical; aliases exist for doc conformance):
  - Suppliers: canonical `GET/POST /company/me/suppliers*` — `.../procurement/suppliers*` are aliases (routes/api.php:755-767 comments).
  - Group send: canonical `POST .../procurement/orders/grouped/{groupId}/send` (r721) — `POST .../procurement/grouped/{groupId}/send` (r725) is the alias.
  - Bulk approve: `POST .../procurement/orders/approve` (r723) is the documented (canonical) bulk path.
  - Company surface `/company/me/procurement/*` is the recommended mount (supports `Idempotency-Key`); platform `/procurement/*` is identical minus idempotency/audit.
- **Enums + Arabic labels** (return/render exactly):
  - Bridge order status: `pending` معلق · `emergency` طارئ · `variance` فرق كميات · `confirmed` معتمد/مؤكد · `rejected` مرفوض · `cancelled_by_branch` ملغي من الفرع · `cancelled_by_supplier` ملغي من المورد · `preparing` قيد التحضير · `on_the_way` في الطريق · `delivered` تم التسليم · `closed` مغلق.
  - Group status: (post-T11.10) `sent` أُرسل للمورد · `confirmed` مؤكد · `preparing` قيد التحضير · `on_the_way` في الطريق · `delivered` تم التسليم.
  - Operations status: `pending` طلبات جديدة · `approved` تم التجميع · `final-approved` أُرسل للمورد · `rejected` مرفوض · `partial_reject` مرفوض جزئياً.
  - `priority`: `high` عاجل ⚡ · `normal` عادي; `orderType`: `direct_supplier` مورد مباشر · `via_purchasing_officer` عبر مسؤول المشتريات · `multiple_sources` مصادر متعددة.
  - Item line status: `pending` معلق · `confirmed` مؤكد · `rejected` مرفوض · `partial` جزئي.
- **Screen mapping**: لوحة التحكم → overview + `purchase-orders?status=incoming`; الطلبات الجديدة → bridge index/show/approve/partial-approve/reject/bulk-approve; الطلبات المجمعة → `grouped` (by=supplier default; by=item once T11.7 lands = the SRS group-card view); المرسلة للموردين → `sent` + `groups/{id}` tracking; الأصناف → items CRUD + price-history + export; الموردون → suppliers CRUD/rate/toggle/export; «أمر شراء جديد» modal → `POST .../procurement/orders`.
- **Quirks the FE doc must call out**: single-object responses have **no envelope wrapper** (object at JSON root; lists = `{data, meta?}`; errors = `{error:{code,message,messageAr}, requestId}` — this module predates the BaseController `{success,...}` format); `branchName` is null in decision responses (reuse from list row); `{id}` on bridge routes accepts UUID or orderNumber; `capacity: null` = supplier didn't declare stock (render «—», not a warning); supplier `rating`/`ratingAvg` is 0–50 (stars×10); group/sent status is derived live — poll to refresh chips; `pageSize` hard cap 100; ⚠️ overview KPI cards currently count the Operations family only — until T11.8, get the real "طلبات جديدة من الفروع" count from `purchase-orders?status=incoming&pageSize=1` → `meta.total`; items columns الاستهلاك الشهري/المخزون الحالي and supplier cards الالتزام %/الإنفاق الشهري have no backend source until T11.11/T11.13.

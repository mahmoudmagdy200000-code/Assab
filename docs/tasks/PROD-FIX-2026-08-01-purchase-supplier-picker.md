# فيكس برودكشن 2026-08-01 — «New Purchase Order»: قائمة الموردين فاضية + كراش num

مشكلتان من الموبايل:

1. مورد عنده أصناف والقائمة بتيجي فاضية («All Suppliers (8)» وتحتها No Search Results Found).
2. شاشة «Choose products & Order Sources» بتكسر بـ `type 'Null' is not a subtype of type 'num' in type cast`.

## 1) سكوبات ناقصة في `Supplier` = 500 على أي فلتر — ✅

`Modules\Supplier\Models\Supplier` ما كانش فيه `available` / `byStatus` / `byDeliveryTime` / `byRating` / `search`، بينما `Purchase\SupplierController::index` و`OrderDataService::getDirectSupplierItems` بينادوا عليها. أي ريكوست فيه فلتر — والبيكر بيبعت `search=` فاضي في كل تحميل — كان `BadMethodCallException` → 500، والأب بيرسمه «No Search Results Found».

- السكوبات الخمسة اتضافت للموديل (`scopeSearch` بيتجاهل النص الفاضي وبيلف الـ ORs في `where` مقفول عشان ما يوسّعش فلتر البراند).
- `SupplierController::index` بقى `filled()` بدل `has()`، و`SupplierStatus::tryFrom` (status غلط = 400 مش 500)، ولو الفرع مش مربوط بعلامة الرسالة بتقول كده صراحة بدل قائمة فاضية بلا سبب.

## 2) «All Suppliers» من غير `item_id` كان TypeError — ✅

`PriceComparisonService::getSuppliers(string $itemId)` كان يفرض الصنف، والراوت `item_id` فيه اختياري. الشاشة اللي بتطلب كل الموردين كانت بتاخد 500.

- التوقيع بقى `getSuppliers(?string $itemId, array $filters = [], ?string $branchId = null)`؛ من غير صنف بيرجع كل موردي العلامة اللي الفرع يقدر يطلب منهم (brand-scoped + fail-closed زي `GET /purchase/suppliers`) ومعاهم أرخص صف من الكتالوج لو موجود.

## 3) `supplier-items` كان بيمشي على `branch_item` مش على كتالوج المورد — ✅

`OrderDataService::getSupplierItems` كان بيعمل paginate لصفوف `branch_item` بتاعة الفرع، فأي صنف المورد بيبيعه والفرع ما خزّنهوش قبل كده (مفيش seed لأن الفرع بره شركة الرافع، أو المورد ضاف الصنف من أبلكيشنه) كان بيتشال من القائمة — ده سبب «المورد عنده أصناف وما بتجيش».

- دلوقتي الـ pagination على `items` المرتبطة بكتالوج المورد، وصف `branch_item` بينضم لو موجود بس (`in_branch_catalog` في الريسبونس يقول للأب لو الصنف جديد على الفرع).
- `getDirectSupplierItems` كمان ما بقاش يرمي 404 «Item not found in your branch» — بيرجع لصف `items` كـ fallback.

## 4) `suppliers_count` كان من المصروفات — ✅

كارت الصنف كان بيعد الموردين من فواتير المصروفات بالاسم (`expense_items.name`)، والبيكر بيعرض موردي العلامة اللي عندهم صف كتالوج للصنف — رقمين مالهمش علاقة ببعض، ومن هنا «(8)» فوق قائمة فاضية.

- `Purchase\Services\SupplierCatalogService` بيحسب العدد من نفس المصدر (`supplier_items` ∪ `supplier_products`، متاح + مورد active + brand-scoped)، و`PurchaseOrderService::getBranchItems` بيحسبه للصفحة كلها مرة واحدة (مفيش N+1، والاستعلام الـ raw القديم اتشال).

## 5) كل رقم في مسار الأوردر بقى non-null — ✅

الأب بيعمل `as num`، فأي null = كراش. اتعمل coalesce في: `SupplierResource` (delivery/min_order/response/rating/orders)، `SupplierItemResource`، صفوف `direct-supplier-items`، صفوف `orders/suppliers`، و`GET /purchase/suppliers/{id}/items` (كان بيرجع موديلات خام: decimal cast = string + nulls).

كمان: `suppliers.status` عمود نصي مش enum — `->status->value` / `?->value` كان Warning بيتحول لـ 500 في `comparePrices` و`OrderSummaryResource` و`PurchaseHistoryDetailsResource`. اتصلح في التلاتة.

## تستات

`tests/Feature/PurchaseSupplierPickerTest.php` — 9 تستات (فلاتر البيكر، status غلط = 400، All Suppliers من غير صنف، فرع غير مربوط، صنف مش مخزّن عند الفرع بيظهر، صفر nulls في الأرقام، تطابق `suppliers_count` مع البيكر). كل التستات المرتبطة (bridge / brand-scope / portal / uploads) خضرا.

## سيرفر

لو مورد لسه ما بيظهرش لصنف: اللينك نفسه ناقص في الكتالوج — `php artisan asab:bridge-backfill --catalog` بيعيد بناء صفوف `supplier_items` المسقوطة. وموردي الإكسل (من غير إيميل/لوجن) مستبعدين من بيكر الأوردرات بقرار ميتنج 2026-07-30.

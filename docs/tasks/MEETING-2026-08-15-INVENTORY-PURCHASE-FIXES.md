# فيكسات 2026-08-15 — الجرد اليومي (أصناف ظاهرة قبل التحديد) + موردو المشتريات (قائمة فاضية)

## 1) الجرد اليومي: الموبايل كان بيعرض كل أصناف الفرع قبل ما الداشبورد يحدد أي حاجة — ✅

**المشكلة:** شاشة «Daily Quick Inventory» بتقول «Selected Products for Today's Inventory (11) — Products
pre-selected by management» بينما الداشبورد على نفس الفرع بيقول «0 صنف / لم يتم اختيار أصناف».

**السبب:** `InventorySessionService::getBranchItems` كان بيقرا **كل** صفوف `branch_item` بتاعة الفرع، وده
كتالوج الفرع كله (رفعة المواد الخام / بريدج المشتريات بيزرعوا فيه)، مش قائمة الجرد. يعني الأصناف كانت
ظاهرة قبل ما المحاسب يحدد أو يبعت.

**الحل:** ورقة الجرد بقت = **جدول الجرد اليومي للفرع** (`daily_inventory_schedule_items`) — اللي بيكتبه
حفظ المحاسب عبر `DailyInventoryListBridgeService`، أو شاشة الجدول عند الفرع نفسه.

- مفيش جدول للفرع، أو جدول من غير أصناف → قائمة **فاضية** (نفس الـ «0 صنف» اللي الداشبورد بيعرضه).
- ترتيب العرض بترتيب `sort_order` اللي اتحدد من الداشبورد، والفلترة القديمة (استبعاد الأصناف اللي في
  سيشن نشط) زي ما هي، و`?include_all=1` زي ما هو.
- صنف موجود في الجدول والفرع ما خزّنهوش قبل كده (مفيش صف `branch_item`) بيفضل في القائمة و**بيتجرد
  عادي**: `CreateInventorySessionRequest` بقى يقبله، والسيرفس بتعمل صف الـ pivot وقت العد بدل ما ترفض
  صنف هي نفسها عرضته.

**الجرد الشهري ما اتأثرش:** `MonthlyInventoryService` كان بيقرا من نفس الميثود، والجرد الشهري بيعد
**كتالوج الفرع كله** مش قائمة اليوم. اتفصلوا: `getAssignedBranchItems()` (كل صفوف `branch_item` — للجرد
الشهري) مقابل `getBranchItems()` (ورقة الجرد اليومي — للشاشة اليومية).

**كمان:** `PUT /accountant/inventory/branches/{id}/daily-list` كان `items => required|array`، يعني `[]`
بترجع 422 — القائمة تتبدّل لكن ما تتفضّاش أبداً. بقت `present|array`، فالمحاسب يقدر يفضّي ورقة الجرد
(البريدج أصلاً بيمسح جدول الفرع لما الاختيار يبقى فاضي).

**أثر على البرودكشن:** فرع ما اتحددتش له قائمة من الداشبورد هيفتح شاشة الجرد فاضية (وده المطلوب).
الفروع اللي المحاسب حافظ لها قائمة بالفعل عندها جدول جاهز؛ ولو فيه فرع محفوظة له قائمة داشبورد من غير
جدول: `php artisan asab:bridge-backfill` (سويب `backfillDailyInventoryLists`) بيبني الجداول من
`asab_branch_inventory_list`.

## 2) المشتريات: «الموردون اللي عندهم الصنف» بترجع فاضية رغم إن الكتالوج مليان — ✅

سببين مختلفين، الاتنين اتصلحوا:

### أ) البريدج كان **بيتخطى** الصنف بدل ما يربطه (السبب الأساسي)

`items.code` عمود **UNIQUE** والجدول من غير عمود tenant. `ProcurementCatalogBridgeService::resolveMobileItem`
كان بيرجع `null` لو لقى صف `items` حيّ بنفس الكود/الاسم مش من صنعه — يعني `syncItem` بيقف قبل ما يكتب
صف `supplier_items` أصلاً. ولأن الكودات (D005…D015) موجودة أصلاً من رفعة المواد الخام للعلامة، كتالوج
المورد ما وصلش للتطبيق أبداً، و`asab:bridge-backfill --catalog` كان بيعيد نفس التخطي.

**الحل:** الصف المشترك بقى **يتربط** (الربط ما بيكسرش حاجة) بدل ما يتخطى، مع عمود جديد
`asab_supplier_items.owns_purchase_item` بيحدد إذا كان الصف مِلكنا:

| الحالة | الاسم/الوحدة/التصنيف | حذف الصنف | صف السعر `supplier_items` |
|---|---|---|---|
| صف صنعناه (owned) | بيتحدّث زي الأول | soft delete + تنضيف seeds | بيتكتب |
| صف مشترك (not owned) | **ما بيتغيّرش** (ملء الفاضي بس) | ما بيتحذفش | بيتكتب ويتقفل بـ `is_available=false` عند الحذف |
| صف **محذوف** لطرف تاني | — | — | لسه بيتخطى (إحياؤه مش قرارنا) |

الميجريشن بيعمل backfill: كل صف مربوط قبل كده = owned (لأن الربط قديماً كان بيحصل وقت الإنشاء بس).

**على السيرفر:** `php artisan asab:bridge-backfill --catalog` — دلوقتي بيداوي فعلاً؛ بيعيد بناء صفوف
`supplier_items` الناقصة لكل أصناف الموردين.

### ب) البيكر بيبعت `id` (صف `branch_item`) مش `item_id`

`PriceComparisonService::getSuppliers` كان بيقارن الـ id بـ `supplier_items.item_id` على طول، فأي id من
نوع pivot = صفر نتايج. بقى يفكّ الاتنين زي `comparePrices` بالظبط.

> ملاحظة مقصودة (قرار ميتنج 2026-07-30): مورد من غير إيميل/لوجن مستبعد من بيكر الأوردرات
> (`SupplierBrandScopeService::orderableSupplierIds` بيشترط `email`).

## 3) «من فرع آخر» — نفس نوع المشكلة، اتصلحت وقائياً ✅

`getBranchesWithStock` كان بيقرا سيشنات الجرد بحالة `completed` بس. الجرد اللي فيه فروقات بيقف عند
`pending_your_confirmation` (مراجعة التقرير بتسجّل timeline بس، ما بتحركش الحالة)، فالفرع اللي لسه جارد
كان **غير مرئي** للتحويل الداخلي. بقى يقرا `completed` + `approved` + `pending_your_confirmation`.
(الفروع اللي أرصدتها 0 وما جردتش بتفضل مش ظاهرة — ده رصيد حقيقي مش باج.)

## التستات

- `tests/Feature/DailyInventoryCountSheetTest.php` — 4 تستات (مفيش قائمة قبل التحديد، الأصناف المجدولة
  بس، تفريغ الجدول = تفريغ الشاشة، صنف مجدول بلا `branch_item` بيتجرد).
- `tests/Feature/InternalTransferStockVisibilityTest.php` — 3 تستات (فرق/completed/draft).
- `ProcurementCatalogBridgeTest` — تست الـ collision اتحوّل من «skipped» لـ «links without clobbering»،
  وتست جديد إن حذف صنف مشترك ما بيحذفش صف الطرف التاني.
- `PurchaseSupplierPickerTest` — تستين جداد: الموردون بالـ `item_id` وبالـ `branch_item.id`.
- خضرا كمان: `DailyQuickInventoryNullSafetyTest` (اتضاف لها جدول جرد)، `AccountantDailyInventoryListTest`،
  `BranchPurchaseItemsReachTheAppTest`، `SupplierPortalCatalogBridgeTest`، `E2eHardeningTest`،
  `InventoryBridgeTest`، `AsabSupplierPortalTest`، `SupplierCredentialSyncTest`، `ProcurementItemsListTest`.

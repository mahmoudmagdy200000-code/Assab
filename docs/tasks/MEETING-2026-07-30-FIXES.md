# Meeting 2026-07-30 fixes — shipped overnight (deadline 07-31)

كل بند من الميتنج اتحلل بمسح متوازي (8 وكلاء استكشاف، file:line evidence) ثم اتنفذ بالترتيب أدناه. Envelopes unchanged. كل فيكس معاه تستات + Pint.

## 1) كراش «الجرد اليومي السريع» في الموبايل — ✅ اتصلح

`type 'Null' is not a subtype of type 'String'`: أصناف مرفوعة من إكسل الداشبورد بتحمل NULL في code/unit/category/subcategory، و`items.logo` أصلاً array مش string، والأصناف المحذوفة soft-delete كانت بتطلع صفوف شبح كلها null.

- كل مسارات القراءة اتعملها coalesce (نفس نمط `BranchItemResource` المعتمد): `InventorySessionService::getBranchItems` (+ `whereHas('item')` لإخفاء الأشباح)، `dashboard()`, `InventoryItemResource`, `DailyInventoryScheduleResource` (+ فيكس 500 كامن), `InventoryTaskListService`, `MonthlyInventoryProductResource`, `WasteDamageProductService`, `WasteDamageReportItemResource`, discrepancy report.
- `item_logo` بقى `logo_url` (string) في كل حتة.
- تطبيع الخلايا الفاضية وقت الرفع في `UploadController::importCatalogRow`.
- تستات: `DailyQuickInventoryNullSafetyTest` (3).

## 2) المصروفات في الداشبورد: المرفقات + اسم الفرع/العلامة + فاتورة يوم 23 — ✅

- **المرفقات**: `ExpenseBridgeService` بقى يعمل mirror لمرفقات الموبايل إلى `asab_attachments` (labels `invoice:i`/`expense`، MIME صحيح، URL حقيقي مش storage key) + `attachment_count`. `ExpenseInvoiceService::attachmentRows` بقى يدمج مستندات الـ statement من الـ payload بدل ما كان يرميها + de-dup.
- **اسم الفرع/العلامة/التاريخ**: `OperationController::present` و`AccountantController::present` بيرجعوا `branchName/brandId/brandName/date` (خرائط batched — مفيش N+1).
- **فاتورة يوم 23**: السبب إن الإنشاء المباشر non-draft لأي نوع مصروف **ماكانش بيطلق أي event** → الجسر عمره ما اشتغل ومفيش لوج. اتصلح في الأربع سيرفيسات (create + draft→pending في الـ update) + `submitted_at` بيتختم عند الإنشاء + `operation_date` بقى تاريخ التقديم/الفاتورة الحقيقي مش وقت تشغيل الجسر. `submitExpense` على pending بقى يعيد إطلاق الحدث بدل 500.
- تستات: `ExpenseSubmissionBridgeTest` (2) + سويتات الجسر القائمة.
- **تشغيل**: `php artisan asab:bridge-backfill` هيلقط فاتورة يوم 23 بتاريخها الصحيح وصورها.

## 3) المورد ↔ الأصناف في أوامر الشراء (مورد عصب/بيتزا) — ✅

- `ProcurementCatalogBridgeService::syncPricedSupplierRow`: كان بيسقط اللينك بصمت لو السعر 0 أو المورد platform (`company_id NULL`) — اتصلح الاتنين. ده سبب «عصب بيبيع بيتزا وما ظهرش».
- `PriceComparisonService::getSuppliers` بقى يدمج `supplier_products` (مخزون المورد نفسه) زي direct-supplier-items — القائمتين بقوا متطابقتين.
- قائمة موردي الأوردرات (`GET /purchase/suppliers`) بقت **موردي الحسابات** بس (brand-scoped + عنده لوجن) — موردي الإكسل الخاصين بالمصروفات مش بيظهروا فيها (طلب الميتنج). fail-closed لفرع غير مربوط.
- `asab:bridge-backfill --catalog` يعيد بناء اللينكات المسقوطة تاريخيًا.
- تستات: `ProcurementCatalogBridgeTest` (+2 جديد، 16 passed).
- **Follow-ups**: عمود مورد اختياري في شيت الخامات؛ `suppliers_count` في الـ picker لسه بيتحسب من المصروفات (تجميلي)؛ فاليديشن الأوردر ضد موردين لا يبيعون الصنف.

## 4) المشتريات → المحاسب (سلسلة الاعتماد) — ✅ جسر جديد كامل

الداشبورد كان جاهز؛ **الجسر موبايل→داشبورد ماكانش موجود أصلًا**. اتبنى:

- `PurchaseOrderBridgeService`: أوردر موبايل (direct supplier / purchasing officer / multiple) يسك عملية `PUR-` بفرع الأوردر (يشوفها محاسب الفرع)، payload بشكل `PurchasePresenterService` (يفعّل الـ 3-way match + `PurchaseReceivingBridge` اللي كان dead code). Internal transfers مستثناة عمدًا.
- Listener على `OrderCreated` (بيتطلق بعد commit — جديد) + `OrderStatusChanged` (delivered/closed بتحدث rcvQty).
- **الجسر العكسي** `PurchaseFeedbackBridgeService`: رفض الهيد يرجع على `purchase_orders` (status + rejection_reason + timeline)، والاعتماد النهائي يتسجل timeline.
- **حلقة إعادة الإرسال**: `REJECTED → PENDING` بقت transition قانونية؛ إعادة الإرسال بعد التعديل تسك عملية PUR **جديدة** بـ `supersedesOperationId` (المرفوضة مقفولة بتصميم الداشبورد).
- Backfill: `asab:bridge-backfill` يمسح الأوردرات القديمة غير المجسّرة.
- تستات: `PurchaseOrderBridgeTest` (4) + السويتات القائمة (39 passed).

## 5) «تحديد الأصناف للجرد»: العلامات التجارية — ✅

- Endpoints جديدة accountant-scoped على السطحين (`/accountant/...` و`/company/me/...`):
  - `GET inventory/brands` (بـ branchCount/itemCount؛ سكوب فاضي = 200 + [])
  - `GET inventory/brands/{brandId}/branches` (متقاطعة مع سكوب المحاسب؛ 404 لعلامة أجنبية)
- `assignedBrandIds()` بقى يستنتج البراند من **مطعم** المحاسب كمان (فروع غير مربوطة بـ asab_brand_id كانت بتقفل الكتالوج على فاضي).
- `storeCatalog` بقى يرفض 422 لفرع غير مربوط بدل ما يسمم الكتالوج بـ `brand_id='unknown'`.
- الفلو للفرونت: brands → branches → `GET inventory/items?brandId=` → `PUT branches/{id}/inventory-list` (تحديث).
- تستات: `InventoryBrandSelectionTest` (5).

## 6) الأصول الثابتة: طلب الاستلام + الإشعار + الظهور في الموبايل — ✅

الجذر: جدولين منفصلين (`asab_assets` داشبورد / `fixed_assets` موبايل) بدون جسر، و`PendingReceipt` عمره ما اتكتب في البرودكشن.

- Migrations: `asab_assets` + received_by_id/received_at/received_note؛ `fixed_asset_pending_receipts` + `asab_asset_id`.
- إضافة أصل من المحاسب (يدوي أو drafts) → event `AssetAssignedToBranch` → listener يعمل `PendingReceipt` (idempotent) + **إشعار FCM حقيقي** للمدير (`ASSET_RECEIVE_REQUESTED` بنسخ عربي/إنجليزي).
- تأكيد الاستلام من الموبايل (شاشات receive-assets القائمة) بقى **يختم رجوعًا** على `asab_assets`: status→`pending_accountant` + received_by/received_at — تظهر فورًا في KPI المحاسب.
- endpoint جديد `GET /branch-manager/fixed-assets/register`: سجل أصول الداشبورد/الإكسل الخاصة بفرع المدير (تحويل هللة→ريال على الحدود).
- تستات: `AssetReceiveBridgeTest` (4).
- **ملاحظة**: أصول الإكسل المرفوعة على مستوى البراند بـ `branch_id NULL` محتاجة شاشة التخصيص (قرار برودكت قائم من قبل).

## 7) الهدر والتالف — مسار الكتابة ✅ / خدمة التجميع follow-up

- **كان بيرمي سطور الأصناف عند الكتابة**: رفع waste/inventory من بوابة الفرع كان بيتحقق من `date/shift/amount` بس ويتجاهل `items`/`products` كليًا → كل الـ ops فاضية. اتصلح (validation كاملة + amount الهدر = مجموع قيم المنتجات).
- **الجرد اليومي** كان بيتخزن بمفتاح `counts` اللي **ولا قارئ واحد** بيقراه — بقى يتخزن `items` (بالاسم والوحدة والسعر) جنب `counts` للتوافق.
- **Follow-up (مُخطط بالكامل في المسح)**: `WasteVarianceService::perItem(branch, month)` = افتتاحي + مشتريات − متبقي = هدر محسوب مقابل هدر مسجل، بأعمدة (المشترى/المتبقي/الفرق/القيمة) + endpoint + دمجه في تقرير waste-analysis. البيانات بقت بتتسجل صح من النهارده فالخدمة تتبني على أرض ثابتة.

## 8) الجرد → المحاسب + الإيميل — الجسر ✅ / الإيميل محتاج SMTP

- جسر جديد `InventoryBridgeService`: تسليم جرد يومي من الموبايل يسك عملية `INV-` بـ `payload.items` (المفتاح اللي كل قارئات ASAB بتلف عليه) و`operation_date = inventory_date` (عشان مقارنة الشهور متتبعثرش)، ويعاد sync عند الاعتماد بأرقام الفروقات (مشتريات/هدر/متوقع). + إشعار داخلي branch-scoped للمحاسب. + backfill arm.
- المقارنة الشهرية موبايل موجودة أصلًا (`GET /inventory/monthly/comparison`)، والشهري مش محتاج إعادة اختيار أصناف (auto-seeded).
- تستات: `InventoryBridgeTest` (3).
- **الإيميل**: البنية جاهزة (`NotificationMail`) لكن `MAIL_MAILER` على السيرفر = `log` — **محتاج SMTP credentials منك** قبل ما نفعّل إيميل ملخص الجرد (وإلا هيتكتب في اللوج بصمت). أول ما تجيب الـ SMTP نوصّلها (نصف ساعة شغل).
- **Follow-up**: تجميع per-category في `InventoryReviewService` (مُخطط بالخطوات في المسح).

## أوامر التشغيل على السيرفر بعد الديبلوي

```bash
/opt/alt/php83/usr/bin/php artisan migrate            # 3 migrations جديدة
/opt/alt/php83/usr/bin/php artisan asab:bridge-backfill --dry-run --catalog
/opt/alt/php83/usr/bin/php artisan asab:bridge-backfill --catalog
# ^ يجسّر: فاتورة يوم 23 + كل أوامر الشراء + جلسات الجرد المقدمة + لينكات الموردين المسقوطة
```

## معروف ومش مننا

نتيجة السويت الكامل النهائية: **794 passed / 3872 assertions — 3 فشلات فقط وكلها pre-existing** (اتأكدنا بتشغيلها على HEAD نضيف قبل أي تعديل):

- `OperationsPipelineTest > procurement status change` (409 vs 422) — transition في مسار procurement الداشبوردي.
- `WasteDamageReportTest > assignment info fails when actor is not branch manager`
- `WasteDamageReportTest > add item and submit succeeds for waste without photo` (بيتوقع `pending_your_confirmation` والكود بيسجل `pending`)
- داشبورد `orchid-crow…/#/preview/asab/ASABPrototype` = بروتوتايب فرونت بتاريخ ثابت «14 أكتوبر 2025» — فلتر «هذا الشهر» المبني عليه هيخفي داتا يوليو 2026. للفرونت.

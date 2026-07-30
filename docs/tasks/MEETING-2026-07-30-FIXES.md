# Meeting 2026-07-30 fixes — shipped overnight (deadline 07-31)

> **تحديث 2026-07-31 — تيست E2E كامل على البرودكشن + إصلاحاته: انظر آخر قسم في الملف.**

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

---

# E2E على البرودكشن (2026-07-31) — النتائج والإصلاحات

تيست متكامل بحسابات الديمو ضد السيرفر المباشر: **8 فلوهات، 92 خطوة (78 نجحت)، 26 باج مؤكد** (كل باج اتأكد بإعادة تشغيله بوكيل مستقل قبل تسجيله). الفلوهات الجوهرية شغالة: مصروفات موبايل→محاسب→هيد حتى الاعتماد النهائي، مشتريات حتى الرفض ورجوعه للموبايل بالسبب والتايم لاين، عزل علامات الجرد بين المحاسبين، جسر الجرد بتاريخه الصحيح.

## إصلاحات كود اتشحنت (2026-07-31)

| # | الباج | الفيكس |
|---|---|---|
| 1 | **500 على قائمة هدر مدير الفرع** — `WasteDamageReportStatus::PENDING_YOUR_CONFIRMATION` غير معرّف (وصفوف legacy في الداتابيز تحمل القيمة → كراش cast) | أُعيدت الحالة للـ enum كقيمة legacy + فيكس التست القديم (كان أحد الفشلين الـ pre-existing) |
| 2 | **ثغرة أمنية: أي مدير/كاشير يعتمد جرد أي فرع** (approve/reject بلا سكوب) | السيرفيس بيطلب `BranchManager` وبيقفل على `branch_id` بتاعه؛ كاشير → 403، فرع أجنبي → 404 |
| 3 | **تسريب SQL خام** (اسم الداتابيز + INSERT كامل) في confirm استلام الأصول | `QueryException` بيتمسك منفصل برسالة عامة + `report()` + حذف صورة الإيصال اليتيمة عند فشل الترانزاكشن |
| 4 | **فاتورة non-tax بتتجسّر بمبلغ 0.00** في جدول فواتير المحاسب | fallback: مجموع سطور الفاتورة ثم إجمالي المصروف (للفاتورة الواحدة) + تست |
| 5 | **إعادة إرسال الأوردر المرفوض مستحيلة** (`submit()` كان DRAFT فقط) | `submit()` بيقبل REJECTED وبيمسح سبب/تاريخ الرفض؛ حلقة التعديل+الإرسال كاملة الآن ويسك عملية PUR جديدة بـ supersedes |
| 6 | `rejected_at` فاضي و`rejected_by` بيظهر **منشئ** الأوردر بدل صاحب قرار الرفض | الجسر العكسي بيختم rejected_at/decided_at/decided_by، والـ resource بيعرض مستخدم ASAB الحقيقي |
| 7 | `time_taken` **سالب** في جلسات الجرد (Carbon 3 signed diff) | الاتجاه اتعكس start→end |
| 8 | **Shift/Expense (و12 موديول) مش متسجلين على `/api` في البرودكشن** — الـ glob في `routes/api.php` حساس لحالة الأحرف (`Routes/` vs `routes/`) على لينكس، وبيفوّت `apilocale` كمان | الـ glob بيلقط الحالتين مع dedupe بالـ realpath — سطح الراوتس بقى متطابق dev/prod |
| 9 | `/api/v1/inventories` بيرجع **صفحة HTML** (سكافولد nwidart ميت تحت auth) | الراوت اتشال |
| 10 | تحديث بند هدر بـ `unit`/`price_per_unit` **بيتتجاهل بصمت** + الـ PUT الجزئي بيمسح `justification_text` | الحقلين اتضافوا للفاليديشن والتحديث بإعادة حساب القيمة، والنص محفوظ |
| 11 | كاشير يقدر يجيب `assignment-info` للهدر (كان الفشل الـ pre-existing الثاني) | `requireManager()` → 403 |
| 12 | **zones/types فاضية** → تأكيد استلام الأصول مستحيل بنيويًا على كل الفروع | `FullDemoSeeder` بينادي `AssetTypeSeeder`+`AssetZoneSeeder` (idempotent — ينفع تشغيلهم على الداتابيز الحالية فورًا) |
| 13 | ديمو ناقص: **صفر عمليات مبيعات، صفر تسليمات عهدة، ولا يوجد مستخدم بوابة فرع** | السييدر بيقفل يومية مدير (→ عملية sales حقيقية عبر الجسر) + تسليمة pending وتسليمة approved لكل فرع مفعّل + مستخدم `branch@nakhat.sa` (رول branch على فرع العليا) |

تستات جديدة: `E2eHardeningTest` (6) + `FullDemoSeederSmokeTest` (1). **الفشلان الـ pre-existing في `WasteDamageReportTest` اتصلحوا** — المتبقي الوحيد المعروف: `OperationsPipelineTest > procurement status change` (409 vs 422، pre-existing وموثّق).

## مطلوب على السيرفر (كونفج — مش كود)

```bash
cd ~/domains/ivory-snail-183262.hostingersite.com/public_html

# 1) بعد سحب الكود الجديد:
/opt/alt/php83/usr/bin/php artisan optimize:clear   # كمان بيصلّح auth/me المعكوسة (build قديم متكاش)

# 2) صور المرفقات كلها 404 — الـ symlink ناقص:
/opt/alt/php83/usr/bin/php artisan storage:link
curl -sI https://ivory-snail-183262.hostingersite.com/storage/ | head -1   # المفروض مش 404 بعدها

# 3) في .env (تسريب معلومات + دبل سلاش في روابط الصور):
#    APP_DEBUG=false
#    APP_URL=https://ivory-snail-183262.hostingersite.com   ← من غير / في الآخر
/opt/alt/php83/usr/bin/php artisan config:clear

# 4) zones/types للأصول (idempotent — بدون إعادة seeding):
/opt/alt/php83/usr/bin/php artisan db:seed --class='Modules\FixedAssets\Database\Seeders\AssetTypeSeeder' --force
/opt/alt/php83/usr/bin/php artisan db:seed --class='Modules\FixedAssets\Database\Seeders\AssetZoneSeeder' --force

# 5) تصحيح روابط الصور المخزنة بالدبل سلاش (يعيد sync العمليات الـ pending):
   
```

> **ملاحظة**: مفيش migrations جديدة في دفعة الإصلاحات دي. متعملش `migrate:fresh` — الداتابيز فيها داتا حقيقية (فاتورة يوم 23 وغيرها) جنب داتا الديمو. مستخدم بوابة الفرع وعمليات المبيعات/التسليمات هيظهروا مع أول استخدام حقيقي، أو مع إعادة seeding كاملة لو قررت تبدأ الديمو من الصفر.

## باقي ملاحظات الـ E2E (مش كود عندنا)

- **البروتوتايب** لسه مثبّت على «14 أكتوبر 2025» — راسل الفرونت (البرومت الكامل في `docs/tasks/FE-INTEGRATION-PROMPT-2026-07-31.md`).
- `auth/me` للموبايل مش بيرجع `branch_id` (فجوة سبيك مش انحراف ديبلوي) — لو الابليكيشن محتاجها نضيفها بسطرين.

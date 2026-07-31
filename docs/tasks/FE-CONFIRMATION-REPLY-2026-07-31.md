# رد الباك اند — تأكيد تفاصيل المشتريات/الجرد + فلتر الفترة (2026-07-31)

> **من:** الباك اند · **إلى:** الفرونت
> كل نقطة اتحققت من الكود الفعلي + الاختبارات (مش من الدوكس). كل بند معلّم **✅ مؤكّد** أو **⚠️ تصحيح** مع المرجع.

---

## أ) تفصيل الشراء `PUR-` — `GET /operations/{id}`

**ملاحظة عامة على الـ envelope:** كل ردود سطح ASAB **bare JSON** — مفيش `{success, data}`. الرد نفسه هو الـ object.

### 1) purchaseItems — ⚠️ تصحيح مكان، ✅ تأكيد أسماء
- المصفوفة القانونية في **`purchases.purchaseItems[]`** (root الرد) — **مش** `payload.purchaseItems`. الرد فيه الاتنين: `payload` (الخام المخزّن كما هو) و`purchases` (بلوك المطابقة المحسوب من `PurchasePresenterService`). **اقرأ من `purchases` دايمًا** — الـ`payload` الخام لعمليات procurement صفوفه `{itemId, qty, unitPriceHalalas?, totalHalalas?}` من غير أسماء صنف/وحدة أصلًا.
- كل صف في `purchases.purchaseItems[]` فيه الـ8 حقول بالظبط بنفس الأسماء: `rowId, item, itemId, unit, ordQty, rcvQty, unitPriceHalalas, orderedUnitPriceHalalas` — **زائد** حقول مشتقة جاهزة: `totalHalalas`, `receivedValueHalalas`, `diffQty`, `qtyMatched`, `priceMatched`, `received`, `lineMatch: {key: 'matched'|'diff'|'pending', labelAr, icon}`, `diffNoteAr`.
- ✅ `rcvQty = null` حرفيًا قبل الاستلام → `lineMatch.key='pending'`, `diffQty=null`, `qtyMatched=null`. اعرض «—». (تِست بيأكد `assertNull`).
- ملاحظة: `item` و`unit` ممكن يكونوا `null` (خصوصًا عمليات procurement) — اعرض «—» برضه.

### 2) الفلوس — ✅ هللة، ⚠️ اسم الإجمالي مختلف
- `unitPriceHalalas` و`orderedUnitPriceHalalas` **int هللة** ✅.
- مفيش `lineTotalHalalas`. الجاهز لكل سطر:
  - `totalHalalas` = ordQty × unitPriceHalalas (قيمة **المطلوب**)
  - `receivedValueHalalas` = rcvQty × unitPriceHalalas (قيمة **المستلم**؛ `null` قبل الاستلام)
  - **متحسبش بنفسك** — خد الجاهز.
- إجماليات العملية: `purchases.summary` = `{ orderedValueHalalas, receivedValueHalalas (0 مش null لو مفيش استلام), lineCount, qtyDiscrepancyCount, priceDiscrepancyCount, pendingReceiptCount, isMatched, hasMismatch }`. وroot الرد فيه `amount` (int هللة = قيمة المطلوب).

### 3) supersedesOperationId — ✅ المكان، ⚠️ القيمة UUID مش publicId
- المكان: **`payload.supersedesOperationId`** ✅ (مش في `purchases` ولا top-level). الحقل **غايب تمامًا** (مش null) لغير عمليات إعادة الإرسال — استخدم optional access.
- القيمة: **UUID خام** للعملية القديمة، **مش** `PUR-…`. عشان البانر: `GET /operations/{uuid}` بيقبل uuid **أو** publicId — هات العملية القديمة واقرأ `publicId` منها (أو اعمل اللينك بالـuuid مباشرة، صفحة التفصيل هتفتح).
- تنبيه: لو الفرع عدّل الطلب تاني والعملية الجديدة لسه pending، الـpayload بيتكتب من أول وجديد والحقل **بيضيع**. ومفيش لينك عكسي (`supersededBy`) على العملية المرفوضة القديمة.

### 4) PATCH purchase-lines — ✅ كله زي ما عندك تقريبًا
- المسار: **`PATCH /api/v1/company/me/operations/{id}/purchase-lines/{rowId}`** ✅ (الهوك بتاعك صح). دور accountant بس على السطح ده. `{id}` بيقبل uuid أو publicId. `{rowId}` هو الـ`rowId` من `purchases.purchaseItems[]` (مطابقة string strict).
- الـbody بالظبط 3 حقول اختيارية: `ordQty` (numeric ≥0)، `rcvQty` (**nullable** numeric ≥0 — ابعت `null` صراحةً يمسح الاستلام ويرجّع السطر pending)، `unitPriceHalalas` (integer ≥0). أي حقل تاني بيتتجاهل.
- الرد: ⚠️ **مش** العملية كاملة — object مضغوط: `{ operationId, rowId, row, match, amount }` حيث `row` = السطر كامل بنفس شكل صف `purchaseItems` (بكل المشتقات)، `match` = حالة العملية الجديدة (`exact|diff|review`)، `amount` = إجمالي العملية المُعاد حسابه (هللة). يكفي تحدّث السطر + البادج + الإجمالي بدون refetch؛ لو بتعرض `summary` اعمل refetch للتفصيل.
- أخطاء: rowId غلط / عملية مش purchases / بره نطاقك → `404 NOT_FOUND`. عملية final-approved/rejected → `409 OP_ALREADY_FINAL` مع `details.currentStatus`.

### 5) هيدر اللوحة — ⚠️ تصحيح
- `purchases.supplierName` ✅ موجود (string|null).
- `orderNumber` و`submittedBy` **مش** في بلوك `purchases` — موجودين بس في **`payload.orderNumber`** / **`payload.submittedBy`** لعمليات الموبايل؛ **غايبين تمامًا** لعمليات procurement → optional access + «—».
- بدائل مضمونة دايمًا في root: `publicId`, `submittedAt`, و`auditTrail[]` (خطوة الـsubmit فيها `by` = اسم مقدّم الطلب أو «تطبيق الفرع»).

### 6) قائمة المشتريات `GET /accountant/operations?moduleKey=purchases` — ✅ مع تحفظين
- الصف فيه 16 مفتاح: `id, publicId, branchId, branchName, brandId, brandName, moduleKey, amount, match, status, origin, attachmentCount, supplierName, submittedAt, date, operationDate`.
- ✅ `amount` int هللة (قيمة المطلوب؛ = 0 لطلبات الفرع bدون تسعير). ✅ `date` = `YYYY-MM-DD`. ✅ `match` ∈ `exact|review|diff` (خام بدون labelAr هنا). ✅ `publicId` = `PUR-0001`.
- ⚠️ `supplierName` هنا من الـpayload مباشرة **بدون** lookup → غالبًا `null` لعمليات procurement/طلبات الفرع. لو محتاج اسم مورد resolved لكل صف استخدم `GET /company/me/operations` — صفوفه فيها `purchaseRow.supplierName` محلول من جدول الموردين.
- الـenvelope: `{ data:[…], meta:{ page, pageSize, total, totalPages, summary:{ totalUploaded, underReview, approved, rejected } } }`. default pageSize=20، أقصى 100.
- ✅ **(اتصلح 2026-07-31)** `meta.summary` بقت ثابتة على كل الصفحات **وبتتجاهل فلتر `?status=`**: الأربع أرقام (totalUploaded/underReview/approved/rejected) بتتحسب على كامل النطاق المفلتر (موديول/فرع/تاريخ/بحث) بغضّ النظر عن الحالة المختارة والصفحة الحالية — اعرضها زي ما هي من أي طلب. (`meta.total` لسه بيحترم كل الفلاتر بما فيها الحالة — استخدمه للـpagination.)

---

## ب) تفصيل الجرد `INV-` — المعادلة اليومية

### 1) المصدر الرسمي — ⚠️ endpoint منفصل
- استخدم **`GET /api/v1/accountant/inventory/branches/{branchId}/daily-reconciliation?date=YYYY-MM-DD`** (date اختياري، default النهاردة). ده المصدر المحسوب الرسمي للمعادلة اليومية per-item + توزيع العجز على الموظفين. (الكتابة: `POST …/daily-variance-allocation`.)
- `payload.items[]` في `GET /operations/{id}` = العدّ الخام المُرسل بس — مفيش أي بلوك محسوب للجرد في تفصيل العملية (البلوكات المحسوبة للـsales/expenses/purchases بس).
- ملاحظة: `daily-reconciliation` تحت `/accountant/*` بس — مفيش mirror على `/company/me`.

### 2) الحقول
- `payload.items[]` (الخام): `{ itemId, name, unit, category, actualQty, purchases, waste, expectedQty }` — زي ما توقعت **+ `category`**. ✅ `purchases/waste/expectedQty = null` حرفيًا قبل اعتماد الجرد في الموبايل (وبعد الاعتماد بيتحدّثوا بس لو العملية لسه pending — لو المحاسب تصرّف قبلها بيفضلوا null). اعرض «—».
- ⚠️ `openingQty/consumed/transfers/expectedClosing/actualClosing` **مش موجودين** في payload.items. موجودين في **snapshot الـreconciliation** بأسماء مختلفة، صف الصنف هناك:
  `{ itemId, itemName, unit, opening, received, consumed, waste, transfers, expectedClosing, actualClosing, equationMatch, expectedQty, actualQty, varianceQty, variancePct, varianceValueHalalas, minLevel, stockStatus:{key: normal|low|critical, labelAr}, status: 'flagged'|'ok', allocatedTo:[{employeeId, employeeName, qty, valueHalalas}] }`
  انتبه: `itemName` مش `name`، و`received` مش `purchases`. `equationMatch = null` للعمليات الجاية من الموبايل (مفيش opening عندها).

### 3) المعادلة الإجمالية — ⚠️ تصحيح
- **مفيش aggregate للمعادلة على مستوى العملية/الفرع** — جمّع أطراف المعادلة من `items[]` بنفسك.
- الوحدات: كل أطراف المعادلة (`opening/received/consumed/waste/transfers/expected/actual/varianceQty`) **كميات** float (3 خانات) — مش فلوس. `variancePct` نسبة float.
- الوحيد بالهللة: على مستوى الـsnapshot `totalVarianceValueHalalas` + `unassignedVarianceValueHalalas` (int)، وعلى مستوى الصنف `varianceValueHalalas` — القيمة **abs()** فالاتجاه (عجز/زيادة) خده من إشارة `varianceQty` (موجب = عجز، لأن variance = expected − actual).
- `amount` على عملية `INV-` = **0 دايمًا** — متستخدموش.

---

## ج) تأكيدات §4 و§5

### §4 تحديد الأصناف للجرد
| Endpoint | الحكم | تفاصيل |
|---|---|---|
| `GET /accountant/inventory/brands` | ✅ | الحقول زي ما عندك بالظبط. **الرد array في الـroot مباشرة** (مش `{data:[]}`). نطاق فاضي = `200` + `[]`. `abbr` ممكن `null`. |
| `GET …/brands/{brandId}/branches` | ✅ | bare array، `restaurantId` ممكن `null` (فرع مربوط بالبراند مباشرة). براند بره نطاقك = `404`. |
| `GET /accountant/inventory/catalog` | ✅ الشكل / ⚠️ brandId | `{ categories, items:[{id, name, cat, unit}] }` — المفتاح `cat` حرفيًا ✅. **`brandId` اختياري** مش إلزامي (من غيره بيتحدد تلقائي بنطاقك)؛ brandId بره النطاق = `200` + items فاضية (**مش** 404). فلاتر إضافية متاحة: `type` (default `sales_item`)، `category`، `search`. |
| `GET …/branches/{branchId}/daily-list` | ✅ | `{ data:[{id, catalogItemId, name, isFlagged}] }`. `name` ممكن `null` لو الصنف اتشال من الكتالوج. بدون pagination. |
| `PUT …/branches/{branchId}/daily-list` | ✅ | body `{ items:[catalogItemId] }` → `{ savedCount, pushedAt }` (ISO+03:00) ✅. `items:[]` فاضية = `422 VALIDATION_ERROR`. ✅ **(اتصلح 2026-07-31)** الـids المكررة في نفس الطلب بقت بتتشال server-side — `savedCount` = عدد الصفوف الفريدة المتخزنة فعلًا. مفيش تحقق إن الـid موجود في الكتالوج — ابعت ids صحيحة. |
| `BRANCH_UNLINKED` | ⚠️ تصحيح | الـ`422 BRANCH_UNLINKED` بيطلع **بس** من `PUT /company/me/inventory/catalog` (إنشاء أصناف + ربط). **daily-list GET/PUT معندهمش الفحص ده** — فرع غير مربوط جوه نطاقك = 200 عادي، وفرع بره النطاق = `404 NOT_FOUND`. شكل الخطأ: `{ error:{ code:'BRANCH_UNLINKED', message, messageAr }, requestId }` — الـ`messageAr` **جوه `error`** مش top-level. |

### §5 إنشاء أصل `POST /accountant/assets`
- **cost:** الحقل اسمه `cost` **أو** `priceHalalas` — الاتنين مقبولين (`required_without`)، **int هللة** min 0. `Math.round(SAR*100)` بتاعك صح ✅. مفيش `costHalalas`.
- **category:** ⚠️ الفاليديشن `required|string|max:32` **بدون enum gate** — الليبل العربي «هيعدّي» لكن هيتخزّن كما هو ويكسر فلتر `?category=` وتجميع الـKPIs. **ابعت المفاتيح الإنجليزية الرسمية:**
  `kitchen` (معدات مطبخ) · `tech` (تقنية وأجهزة) · `furniture` (أثاث ومفروشات) · `vehicles` (مركبات) · `construction` (صيانة وإنشاءات) · `electrical` (معدات كهربائية) · `smallwares` (أدوات تشغيل ومستهلكات) · `software` (برمجيات وتراخيص) · `other` (أخرى)
  والقايمة متاحة ديناميك من `GET /company/me/lookups/asset-categories`. (ليبلاتك «معدات/تقنية/أثاث» مش مطابقة للـlabelAr الرسمية — استخدم القايمة دي للعرض.)
- **branchId:** ✅ إلزامي (`required|string`) + لازم يكون جوه نطاق المحاسب وإلا `404`.
- باقي الحقول: `name` required max:200 · `usefulLifeMonths` required `in:24,36,48,60,72,84` · `invNum`/`serial` nullable max:64 · `purchaseDate` nullable date (default = دلوقتي) · `custodian` nullable max:200 · `notes` nullable.
- الرد: `201` bare object فيه `publicId` (`FA-0001`)، `status:'pending_branch'` + `statusLabelAr`، `categoryLabelAr`، `cost`/`priceHalalas`/`bookValue`/`bookValueHalalas`، `monthlyDepreciationHalalas`/`annualDepreciationHalalas`، إلخ. بيبعت إشعار تأكيد للفرع تلقائي.

---

## د) فلتر الفترة في الشريط العلوي — قرارنا

**اعمله فلتر فعّال server-side.** التفاصيل:

1. **الحقل/الصيغة:** ابعت **`dateFrom` / `dateTo`** (camelCase) بصيغة `YYYY-MM-DD` — السيرفر بيفلتر `whereDate` على `operation_date`، شامل الطرفين. مدعوم فعلًا على: `GET /accountant/operations`، كل `GET /head/operations/*` (بما فيها `view=grouped`)، و`GET /operations` / `/company/me/operations`.
2. **النطاق:** **صندوق/قوائم العمليات بس.** الـKPIs الرئيسية (`/accountant/dashboard`، `/head/dashboard`) **مش بتقبل** أي period params حاليًا — فلتر عام عليهم محتاج شغل باك اند جديد (قولّنا لو عايزينه نضيفه). اللي بيقبل فترات: `expenses/kpis` (dateFrom/dateTo)، `sales/kpis` (`date` يوم واحد)، `dashboard/activity-heatmap`، `head/accountants/performance`.
3. **المصدر:** **server-side إجباري** — القوائم paginated (default 20 / cap 100)، أي فلترة client-side على صفحة محمّلة هتسقط صفوف من الصفحات التانية by construction. كمان `dateFrom/dateTo` السيرفرية بتخلي `meta.summary` وإجماليات الهيد تحترم الفترة.
4. **الافتراضي:** «**الكل**» (بدون dateFrom/dateTo) — عشان مانرجعش لنفس عرض «فين الداتا؟». لو المنتج أصرّ على «هذا الشهر» افتراضيًا يبقى من `startOfMonth` الحقيقي — التاريخ بقى ديناميكي فمفيش خطر.
5. **علاقته بفلاتر الموديولات:** خليه فلتر عام بيغذّي `dateFrom/dateTo` لقوائم العمليات؛ فلاتر الصفحات الداخلية تفضل مستقلة (هي أصلًا بتبعت باراميتراتها بنفسها). لو مش هتنفّذه بالشكل ده دلوقتي → شيل الـselect الديكوري.
   - ملحوظة client-side لو احتجت تفلتر معروض محليًا: صفوف المحاسب فيها `date` (YYYY-MM-DD) و`operationDate` (ISO)؛ **صفوف الهيد فيها `operationDate` بس** — مفيش `date`.

---

## ملاحظات باك اند — ✅ الثلاثة اتصلحوا (2026-07-31، نفس اليوم)
1. ✅ `meta.summary` في `/accountant/operations`: العدّادات بقت بتتحسب قبل الـpagination وبدون فلتر الحالة (كويري `groupBy(status)` واحد) — ثابتة على كل الصفحات وكل تابات الحالة. تِستان جديدان بيثبّتوها.
2. ✅ `PUT daily-list`: dedupe server-side للـids المكررة — مفيش 500، و`savedCount` بيعكس المتخزن فعلًا. تِست جديد.
3. ✅ تِستات HTTP جديدة: بانر `supersedesOperationId` في رد الـshow (uuid بيتحل لـpublicId عبر نفس الـendpoint)، والـ`422 BRANCH_UNLINKED` بشكل الـenvelope الكامل.

(66 تِست باس على الملفات الأربعة المتأثرة + ExpenseBridgeTest ريجريشن، وPint نضيف.)

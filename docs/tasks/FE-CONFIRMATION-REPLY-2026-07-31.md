# رد الباك اند — تأكيد تفاصيل المشتريات/الجرد + فلتر الفترة

> **من:** الباك اند · **إلى:** الفرونت · **التاريخ:** 2026-07-31
> كل نقطة متحققة من **الكود الفعلي والتِستات** (مش من الدوكس). التنسيق: ✅ مؤكّد زي ما افترضت / ⚠️ تصحيح.
> ملاحظة عامة: كل ردود سطح ASAB **bare JSON** — مفيش غلاف `{success, data}`. الرد نفسه هو الـobject/الـarray.

---

## 🔴 TL;DR — اللي محتاج يتغيّر عندك (12 نقطة)

| # | التغيير | القسم |
|---|---|---|
| 1 | أسطر الشراء تتقري من **`purchases.purchaseItems[]`** (root الرد) — مش `payload.purchaseItems` | أ-1 |
| 2 | مفيش `lineTotalHalalas` — استخدم الجاهز: `totalHalalas` (مطلوب) / `receivedValueHalalas` (مستلم) — **متحسبش بنفسك** | أ-2 |
| 3 | `supersedesOperationId` = **uuid** مش `PUR-…` — اعمل GET تاني بيه عشان تجيب الـpublicId للبانر | أ-3 |
| 4 | رد الـPATCH **مش** العملية كاملة — `{operationId, rowId, row, match, amount}`؛ refetch بس لو بتعرض `summary` | أ-4 |
| 5 | `orderNumber`/`submittedBy` optional-access من `payload.*` — موجودين لعمليات الموبايل بس | أ-5 |
| 6 | `supplierName` في قائمة المحاسب ممكن `null` — الاسم المحلول في `purchaseRow.supplierName` على `/company/me/operations` | أ-6 |
| 7 | معادلة الجرد من **endpoint منفصل** `daily-reconciliation` — أسماء الحقول هناك مختلفة (`itemName`, `opening`, `received`…) | ب |
| 8 | `brands` و`branches` بيرجعوا **array في الـroot مباشرة** — مش `{data:[]}` | ج-§4 |
| 9 | `brandId` في الكتالوج **اختياري**؛ بره النطاق = 200 + فاضي (مش 404)؛ المفتاح `cat` حرفيًا | ج-§4 |
| 10 | `BRANCH_UNLINKED` بيطلع من `PUT /company/me/inventory/catalog` **بس** — مش من daily-list | ج-§4 |
| 11 | `category` في الأصول: ابعت **المفاتيح الإنجليزية** (`kitchen`/`tech`/…) مش الليبل العربي | ج-§5 |
| 12 | فلتر الفترة العلوي: نفّذه **server-side** بـ`dateFrom`/`dateTo` على قوائم العمليات بس، افتراضي «الكل» | د |

---

## أ) تفصيل عملية الشراء `PUR-` — `GET /operations/{id}`

### أ-1) purchaseItems — ⚠️ المكان اتصحّح، ✅ الأسماء زي ما افترضت

الرد فيه مفتاحين متجاورين في الـroot:
- **`payload`** = الـpayload الخام المخزّن كما هو (شكله بيختلف حسب مصدر العملية — متعتمدش عليه للجدول).
- **`purchases`** = بلوك المطابقة القانوني المحسوب. **اقرأ منه دايمًا.**

كل صف في `purchases.purchaseItems[]`:

```jsonc
{
  // الـ8 حقول اللي سألت عليها — بنفس الأسماء بالظبط:
  "rowId": "itm-1",              // string — ده اللي بتبعته في الـPATCH
  "item": "طماطم",               // string|null ← اعرض «—» لو null
  "itemId": "…",                 // string|null
  "unit": "كجم",                 // string|null
  "ordQty": 10.0,                // float
  "rcvQty": null,                // float|null — null قبل الاستلام ✅ ← «—»
  "unitPriceHalalas": 500,       // int هللة
  "orderedUnitPriceHalalas": 500,// int هللة

  // مشتقات جاهزة — استخدمها بدل أي حساب عندك:
  "totalHalalas": 5000,          // int = ordQty × unitPriceHalalas (قيمة المطلوب)
  "receivedValueHalalas": null,  // int|null = rcvQty × unitPriceHalalas (null قبل الاستلام)
  "diffQty": null,               // float|null = rcv − ord (null قبل الاستلام)
  "qtyMatched": null,            // bool|null
  "priceMatched": true,          // bool
  "received": false,             // bool
  "lineMatch": { "key": "pending", "labelAr": "…", "icon": "…" }, // matched|diff|pending
  "diffNoteAr": null             // string|null — نص الفرق جاهز بالعربي
}
```

`rcvQty = null` حرفيًا لحد ما يتم الاستلام (من الموبايل أو بتعديل المحاسب) → `lineMatch.key = 'pending'` ✅.

### أ-2) الفلوس — ✅ هللة int، ⚠️ اسم الإجمالي

- `unitPriceHalalas` / `orderedUnitPriceHalalas` **int بالهللة** ✅.
- مفيش `lineTotalHalalas`. الجاهز: `totalHalalas` و`receivedValueHalalas` (فوق). **متحسبش `rcvQty × unitPriceHalalas` بنفسك.**
- إجماليات العملية في `purchases.summary`:
  ```jsonc
  { "orderedValueHalalas": 5000, "receivedValueHalalas": 0,   // 0 مش null لو مفيش استلام
    "lineCount": 1, "qtyDiscrepancyCount": 0, "priceDiscrepancyCount": 0,
    "pendingReceiptCount": 1, "isMatched": false, "hasMismatch": false }
  ```
- root الرد فيه كمان `amount` (int هللة = قيمة المطلوب الإجمالية).

### أ-3) supersedesOperationId — ✅ المكان، ⚠️ القيمة uuid

- المكان: **`payload.supersedesOperationId`** ✅.
- القيمة: **uuid** العملية المرفوضة القديمة — **مش** `PUR-…`. عشان البانر: `GET /operations/{id}` بيقبل **uuid أو publicId** — هات العملية القديمة بالـuuid واقرأ منها `publicId` (أو اعمل اللينك بالـuuid مباشرة وصفحة التفصيل هتفتح عادي).
- الحقل **غايب تمامًا** (مش `null`) لأي عملية مش إعادة إرسال → استخدم optional access: `payload?.supersedesOperationId`.
- مفيش لينك عكسي (`supersededBy`) على العملية المرفوضة القديمة.

### أ-4) تعديل السطر `PATCH` — ✅ المسار والـbody، ⚠️ شكل الرد

- **المسار:** `PATCH /api/v1/company/me/operations/{id}/purchase-lines/{rowId}` ✅ (الهوك بتاعك صح). `{id}` = uuid أو publicId. `{rowId}` = من `purchases.purchaseItems[]`.
- **الـbody** (3 حقول اختيارية بالظبط — أي حاجة زيادة بتتتجاهل):
  ```jsonc
  { "ordQty": 10,            // numeric ≥ 0
    "rcvQty": 2.5,           // numeric ≥ 0 — nullable: ابعت null صراحةً يمسح الاستلام ويرجّع السطر pending
    "unitPriceHalalas": 550  // integer ≥ 0 (هللة)
  }
  ```
- **الرد** (200):
  ```jsonc
  { "operationId": "…", "rowId": "itm-1",
    "row": { /* السطر كامل بنفس شكل صف purchaseItems بكل المشتقات */ },
    "match": "exact",        // حالة العملية الجديدة: exact|diff|review
    "amount": 7000 }         // إجمالي العملية المُعاد حسابه (هللة)
  ```
  يكفي تحدّث السطر + بادج المطابقة + الإجمالي **بدون refetch**. لو بتعرض `purchases.summary` → اعمل refetch للتفصيل.
- **الأخطاء:** rowId غلط / عملية مش purchases / بره نطاقك → `404 NOT_FOUND` · عملية final-approved/rejected → `409 OP_ALREADY_FINAL` + `details.currentStatus`.

### أ-5) هيدر اللوحة — ⚠️ تصحيح

- `purchases.supplierName` ✅ (string|null).
- `orderNumber` و`submittedBy`: **مش** في بلوك `purchases` — موجودين في **`payload.orderNumber`** / **`payload.submittedBy`** لعمليات الموبايل، و**غايبين تمامًا** لعمليات procurement → optional access + «—».
- بدائل مضمونة دايمًا في root الرد: `publicId`، `submittedAt`، و`auditTrail[]` (خطوة الـsubmit فيها `by` = اسم مقدّم الطلب أو «تطبيق الفرع»).

### أ-6) قائمة المشتريات `GET /accountant/operations?moduleKey=purchases` — ✅ + تحفظ واحد

- ✅ `moduleKey` مدعوم. باقي الفلاتر: `status`, `branchId`, `dateFrom`, `dateTo`, `search`, `page`, `pageSize` (default 20، أقصى 100).
- الصف = 16 مفتاح:
  `id, publicId, branchId, branchName, brandId, brandName, moduleKey, amount, match, status, origin, attachmentCount, supplierName, submittedAt, date, operationDate`
- تأكيداتك: ✅ `amount` int هللة (قيمة المطلوب؛ = 0 لطلبات الفرع بدون تسعير) · ✅ `date` = `YYYY-MM-DD` (وفيه كمان `operationDate` ISO) · ✅ `match` ∈ `exact|review|diff` · ✅ `publicId` = `PUR-0001`.
- ⚠️ `supplierName` هنا من الـpayload مباشرة بدون lookup → غالبًا `null` لعمليات procurement وطلبات الفرع. لو محتاج اسم مورد محلول لكل صف → استخدم `GET /company/me/operations` (صفوفه فيها `purchaseRow.supplierName`).
- **الـenvelope:** `{ data:[…], meta:{ page, pageSize, total, totalPages, summary:{ totalUploaded, underReview, approved, rejected } } }`
- **سلوك `meta.summary`:** الأربع أرقام بتتحسب على **كامل النطاق المفلتر** (موديول/فرع/تاريخ/بحث) وبتتجاهل `?status=` والصفحة الحالية — ثابتة على كل الصفحات وكل التابات، اعرضها زي ما هي من أي طلب. `meta.total` هو اللي بيحترم كل الفلاتر (استخدمه للـpagination).

---

## ب) تفصيل الجرد `INV-` — المعادلة اليومية

### ب-1) المصدر الرسمي — ⚠️ endpoint منفصل

استخدم: **`GET /api/v1/accountant/inventory/branches/{branchId}/daily-reconciliation?date=YYYY-MM-DD`**
(`date` اختياري — default النهاردة. الكتابة/التوزيع: `POST …/daily-variance-allocation`. متاح تحت `/accountant/*` بس — مفيش mirror على `/company/me`.)

`payload.items[]` في `GET /operations/{id}` = **العدّ الخام المُرسل بس** — مفيش أي بلوك محسوب للجرد في تفصيل العملية.

### ب-2) الحقول

**الخام** — `payload.items[]` (لشاشة مراجعة العملية العامة):
```jsonc
{ "itemId": "…", "name": "…", "unit": "kg", "category": "…",   // + category زيادة عن قايمتك
  "actualQty": 10.0,
  "purchases": null, "waste": null, "expectedQty": null }       // null حرفيًا قبل اعتماد الجرد في الموبايل ← «—»
```
(بعد الاعتماد بيتحدّثوا **بس لو** العملية لسه pending — لو المحاسب تصرّف قبلها بيفضلوا null.)

**المحسوب** — صف الصنف في `daily-reconciliation` (لاحظ الأسماء المختلفة):
```jsonc
{ "itemId": "…", "itemName": "…", "unit": "kg",                 // itemName مش name!
  "opening": 100.0, "received": 20.0, "consumed": 0.0,          // received مش purchases!
  "waste": 5.0, "transfers": 0.0,
  "expectedClosing": 115.0, "actualClosing": 110.0,
  "equationMatch": false,           // bool|null — null لعمليات الموبايل (مفيش opening عندها)
  "expectedQty": 115.0, "actualQty": 110.0,
  "varianceQty": 5.0,               // = expected − actual → موجب = عجز (الاتجاه من هنا)
  "variancePct": 4.35,
  "varianceValueHalalas": 5000,     // int هللة — قيمة مطلقة abs()
  "minLevel": 20.0, "stockStatus": { "key": "low", "labelAr": "…" },  // normal|low|critical
  "status": "flagged",              // flagged|ok
  "allocatedTo": [{ "employeeId": "…", "employeeName": "…", "qty": 2, "valueHalalas": 2000 }] }
```
وعلى مستوى الرد: `{ branchId, branchName, date, items, totalVarianceValueHalalas, unassignedVarianceValueHalalas }`.

### ب-3) المعادلة الإجمالية — ⚠️ تصحيحان

1. **مفيش aggregate لأطراف المعادلة** على مستوى العملية/الفرع — جمّعها من `items[]` بنفسك.
2. **الوحدات:** كل أطراف المعادلة **كميات** float (3 خانات) — مش فلوس. الهللة بس في `varianceValueHalalas` (per-item، abs) و`totalVarianceValueHalalas`/`unassignedVarianceValueHalalas` (إجمالي).
3. `amount` على عملية `INV-` = **0 دايمًا** — متستخدموش لأي عرض.

---

## ج) تأكيدات §4 و§5

### §4 «تحديد الأصناف للجرد»

| Endpoint | الحكم | التفاصيل |
|---|---|---|
| `GET /accountant/inventory/brands` | ✅ | الحقول `{id, name, abbr, branchCount, itemCount}` ✅. ⚠️ الرد **array في الـroot** مش `{data:[]}`. نطاق فاضي = `200` + `[]` ✅. `abbr` ممكن `null`. |
| `GET …/brands/{brandId}/branches` | ✅ | `{id, name, restaurantId, listItemCount}` ✅ — bare array برضه. `restaurantId` ممكن `null` (فرع مربوط بالبراند مباشرة). براند بره نطاقك = `404`. |
| `GET /accountant/inventory/catalog` | ✅/⚠️ | الشكل `{categories, items:[{id, name, cat, unit}]}` ✅ — المفتاح **`cat`** حرفيًا. ⚠️ `brandId` **اختياري** (من غيره بيتنطق بنطاقك تلقائي)؛ brandId بره النطاق = `200` + items فاضية (**مش** 404). فلاتر إضافية: `type` (default `sales_item`)، `category`، `search`. |
| `GET …/branches/{branchId}/daily-list` | ✅ | `{data:[{id, catalogItemId, name, isFlagged}]}` ✅. `name` ممكن `null` لو الصنف اتشال من الكتالوج ← «—». بدون pagination. |
| `PUT …/branches/{branchId}/daily-list` | ✅ | body `{items:[catalogItemId]}` → `{savedCount, pushedAt}` (ISO `+03:00`) ✅. `items:[]` فاضية = `422 VALIDATION_ERROR`. الـids المكررة بتتشال server-side — `savedCount` = الصفوف الفريدة المتخزنة فعلًا. مفيش تحقق من وجود الـid في الكتالوج — ابعت ids صحيحة. |

**`BRANCH_UNLINKED`** — ⚠️ تصحيح: الـ`422` ده بيطلع **بس** من `PUT /company/me/inventory/catalog` (إنشاء أصناف + ربط). daily-list GET/PUT معندهمش الفحص — فرع غير مربوط جوه نطاقك = 200 عادي، وفرع بره النطاق = `404`. شكل الخطأ (لاحظ `messageAr` **جوه** `error`):
```jsonc
{ "error": { "code": "BRANCH_UNLINKED",
             "message": "Branch is not linked to a brand",
             "messageAr": "الفرع غير مرتبط بعلامة تجارية — اربط الفرع أولاً من إدارة الفروع" },
  "requestId": "req_…" }
```

### §5 إنشاء أصل — `POST /accountant/assets`

- **cost:** الاسم `cost` **أو** `priceHalalas` — الاتنين مقبولين (أي واحد منهم إلزامي)، **int هللة** ≥ 0. حسبتك `Math.round(SAR*100)` ✅. مفيش `costHalalas`.
- **category:** ⚠️ الفاليديشن `string|max:32` بدون enum — العربي «هيعدّي» لكن هيتخزن حرفيًا و**هيكسر** فلتر `?category=` وتجميع الـKPIs. **ابعت المفاتيح الإنجليزية:**

  | key | labelAr |
  |---|---|
  | `kitchen` | معدات مطبخ |
  | `tech` | تقنية وأجهزة |
  | `furniture` | أثاث ومفروشات |
  | `vehicles` | مركبات |
  | `construction` | صيانة وإنشاءات |
  | `electrical` | معدات كهربائية |
  | `smallwares` | أدوات تشغيل ومستهلكات |
  | `software` | برمجيات وتراخيص |
  | `other` | أخرى |

  والقايمة متاحة ديناميك من `GET /company/me/lookups/asset-categories` — استخدمها للـdropdown بدل hardcoding.
- **branchId:** ✅ إلزامي + لازم جوه نطاق المحاسب وإلا `404`.
- **باقي الحقول:** `name` required max:200 · `usefulLifeMonths` required وقيمه المسموحة `24|36|48|60|72|84` بس · `invNum`/`serial` nullable max:64 · `purchaseDate` nullable date (default = وقت الإنشاء) · `custodian` nullable max:200 · `notes` nullable.
- **الرد:** `201` bare object: `publicId` (`FA-0001`)، `status: 'pending_branch'` + `statusLabelAr`، `categoryLabelAr`، `cost`/`priceHalalas`/`bookValue`/`bookValueHalalas` (هللة)، `monthlyDepreciationHalalas`/`annualDepreciationHalalas`، `purchaseDate` ISO، إلخ. وبيبعت إشعار تأكيد للفرع تلقائي.

---

## د) فلتر الفترة في الشريط العلوي — القرار

**نفّذه فلتر فعّال server-side.** إجابة نقاطك:

1. **الحقل/الصيغة:** ابعت **`dateFrom` / `dateTo`** (camelCase) بصيغة `YYYY-MM-DD` — الفلترة `whereDate` على `operation_date`، **شاملة الطرفين**. مدعوم جاهز على: `GET /accountant/operations` · كل `GET /head/operations/*` (بما فيها `view=grouped`) · `GET /operations` و`/company/me/operations`.
2. **النطاق:** **قوائم/صندوق العمليات بس.** الداشبوردات الرئيسية (`/accountant/dashboard`، `/head/dashboard`) **مش بتقبل** period params حاليًا — لو عايزين فلتر عام يشمل الـKPIs قولولنا نضيفه كشغل منفصل. اللي بيقبل فترات دلوقتي: `expenses/kpis` (dateFrom/dateTo) · `sales/kpis` (`date` يوم واحد) · `dashboard/activity-heatmap` · `head/accountants/performance`.
3. **المصدر:** **server-side إجباري** — القوائم paginated (default 20 / cap 100)؛ أي فلترة client-side على صفحة محمّلة هتسقط صفوف من الصفحات التانية by construction. كمان الفلترة السيرفرية بتخلي `meta.summary` وإجماليات الهيد تحترم الفترة.
4. **الافتراضي:** «**الكل**» (متبعتش dateFrom/dateTo) — عشان مانرجعش لعرض «فين الداتا؟». لو المنتج أصرّ على «هذا الشهر» يبقى من `startOfMonth` الحقيقي.
5. **علاقته بفلاتر الموديولات:** فلتر عام بيغذّي `dateFrom/dateTo` لقوائم العمليات؛ فلاتر الصفحات الداخلية مستقلة زي ما هي. لو مش هتنفّذه كده دلوقتي → شيل الـselect الديكوري.
   - للـclient-side لو احتجته على معروض محليًا: صفوف المحاسب فيها `date` (YYYY-MM-DD) و`operationDate` (ISO)؛ **صفوف الهيد فيها `operationDate` بس** — مفيش `date`.

---

## ملحق — تحديثات باك اند نُشرت النهاردة (2026-07-31)

عشان لو كنت شايف سلوك مختلف قبل كده:

1. **`meta.summary` في `/accountant/operations`:** كانت بتصفّر العدّادات من page≥2 ومع فلتر `?status=`. اتصلحت — دلوقتي ثابتة على كل الصفحات والتابات (السلوك الموصوف في أ-6 فوق).
2. **`PUT daily-list`:** كان بيرمي 500 لو نفس `catalogItemId` اتكرر في نفس الطلب. اتصلح — dedupe server-side (مش محتاج dedupe عندك، بس نضّف الداتا برضه أحسن).
3. تِستات HTTP جديدة بتثبّت: بانر supersedes، خطأ `BRANCH_UNLINKED`، وثبات الـsummary.

---

## بعد كده
- **أ) وب):** نفّذ اللوحتين بالأشكال اللي فوق — كلها live ومثبّتة بتِستات.
- **ج):** التعديلات المطلوبة عندك محصورة في جدول الـTL;DR (نقط 8–11).
- **د):** لو هتنفّذ الفلتر بالسيمانتيك اللي فوق مش محتاج مننا حاجة؛ لو عايزينه يشمل الـKPIs ابعتلنا نفتحله تاسك.
- أي حقل طالع عندك مختلف عن الموصوف هنا ابعته فورًا — الدوك ده متولّد من الكود الحالي مباشرة.

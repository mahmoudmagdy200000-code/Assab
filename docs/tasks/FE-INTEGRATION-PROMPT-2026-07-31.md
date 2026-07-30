# برومت ربط الفرونت إند — داشبورد ASAB (2026-07-31)

> الصق هذا الملف كاملًا كبرومت لفريق/وكيل الفرونت إند. كل المسارات والأشكال أدناه منسوخة من الكود الفعلي وتم اختبارها على السيرفر المباشر.

---

أنت مطور فرونت إند تربط داشبورد ASAB (البروتوتايب الحالي) بالباك إند الحقيقي. الباك إند جاهز ومنشور ومزروع بداتا ديمو كاملة. مطلوب استبدال كل الداتا الوهمية في البروتوتايب بنداءات API حقيقية حسب المواصفات التالية بالحرف.

## 0) الأساسيات

- **Base URL**: `https://ivory-snail-183262.hostingersite.com/api/v1`
- كل الردود **JSON خام بدون غلاف** `{success, data}` — الرد هو الكائن/المصفوفة مباشرة. بعض القوائم ترجع `{data: [...], meta: {...}}` والمقسّمة صفحات ترجع meta بها `page/pageSize/total`.
- **الأخطاء** بشكل موحد:
  ```json
  { "error": { "code": "VALIDATION_ERROR", "message": "...", "messageAr": "...", "details": {} }, "requestId": "req_..." }
  ```
  اعرض `error.messageAr` للمستخدم دائمًا. عند `VALIDATION_ERROR` تجد أخطاء الحقول في `details`.
- **الفلوس**: كل حقل `amount` أو `*Halalas` عدد صحيح **بالهللة** → للعرض اقسم على 100 (`1720000` → `17,200.00 ر.س`).
- **التواريخ**: ISO 8601. حقل `date` في صفوف العمليات = يوم العملية `YYYY-MM-DD`.
- **سكوب فاضي = `200` + مصفوفة فاضية** (ليس 403) → اعرض empty state.

### 🔴 باج إلزامي في الفرونت نفسه
البروتوتايب مثبّت فيه تاريخ اليوم على «14 أكتوبر 2025». **أزل التثبيت واستخدم تاريخ النظام الحقيقي**. فلتر «هذا الشهر» يجب أن يُبنى على حقل `date` (يوم العملية) — حاليًا التثبيت يخفي داتا يوليو 2026 الحية بالكامل.

## 1) المصادقة

```
POST /auth/login          { "email": "...", "password": "..." }
→ { accessToken, refreshToken, expiresIn: 900, user: { id, name, email, avatar, companyId, defaultPage, roles: [...] } }

POST /auth/refresh        { "refreshToken": "..." }   → زوج توكنات جديد
GET  /auth/me             (Bearer)
POST /auth/logout
```

- أرسل `Authorization: Bearer {accessToken}` في كل نداء.
- **الـ accessToken عمره 15 دقيقة**: عند أي `401` اعمل refresh تلقائي وأعد النداء؛ لو الـ refresh فشل → شاشة الدخول.
- التوجيه حسب الدور من `user.roles[0]`:
  ```json
  { "key": "accountant", "scope": "brand", "brandIds": ["..."], "restaurantIds": [], "branchIds": [],
    "moduleKeys": ["sales","expenses","purchases","inventory","shifts","assets"] }
  ```
  `key` ∈ `head | admin | accountant | procurement | brand_owner | branch`. أظهر تبويبات الموديولات من `moduleKeys` فقط.

## 2) صندوق عمليات المحاسب (الشاشة الرئيسية)

```
GET /accountant/operations        (فلاتر: moduleKey, status, branchId, dateFrom, dateTo, page, pageSize)
```

شكل صف القائمة (كل الحقول موجودة فعليًا — **اعرض الأسماء وليس الـ IDs**):

```json
{
  "id": "uuid", "publicId": "EXP-0011",
  "branchId": "uuid", "branchName": "فرع العليا",
  "brandId": "uuid",  "brandName": "برجر بيت",
  "moduleKey": "expenses", "amount": 300800, "match": "exact",
  "status": "pending", "origin": "mobile",
  "attachmentCount": 1, "supplierName": "مورد كذا",
  "submittedAt": "2026-07-22T21:00:00+00:00",
  "date": "2026-07-23", "operationDate": "2026-07-22T21:00:00+00:00"
}
```

- عمود التاريخ من `date`. بادج 📎 من `attachmentCount`. عمود المورد (`supplierName`) للمصروفات والمشتريات.
- `moduleKey` ∈ `sales | expenses | purchases | inventory | shifts | waste`. بادئات `publicId`: `OPS/EXP/PUR/INV...`.
- `origin: "mobile"` = جاية من تطبيق الفرع — أظهر أيقونة موبايل.

```
GET  /operations/{id}                  التفاصيل (تشمل payload حسب الموديول — أدناه)
GET  /operations/{id}/attachments      ملفات حقيقية: [{ id, filename, mimeType, size, publicUrl, label, uploadedAt }]
GET  /operations/{id}/audit-trail
POST /operations/{id}/approve          (محاسب)      POST /operations/{id}/reject { reason }
POST /operations/{id}/request-clarification          POST /operations/{id}/correction
POST /operations/bulk-approve
```

**المرفقات**: اعرض الصور/PDF من `publicUrl` مباشرة (رابط كامل يحوي `/storage/`). لا تستخدم `storageKey` أبدًا للعرض. `label` بصيغة `invoice:0` يربط الملف بالفاتورة رقم 0 في `payload.invoices`، و`expense` = مستند عام.

## 3) payload حسب الموديول

### مصروفات (`moduleKey=expenses`)
```json
{
  "invoices": [ { "invNum": "123", "vendor": "اسم المورد", "supplierId": "uuid", "desc": "...",
                  "date": "2026-07-23", "amountHalalas": 300800, "vatHalalas": 39235,
                  "attachments": [ { "id","filename","mimeType","size","storageKey","publicUrl" } ] } ],
  "attachments": [ "...نفس شكل الملف (مستندات عامة)..." ],
  "supplierName": "...", "submittedBy": "اسم مدير الفرع",
  "expenseType": "...", "paymentMethod": "...", "legacyStatus": "..."
}
```
اعرض جدول الفواتير + `submittedBy` («قدّمها: …»).

### مشتريات (`moduleKey=purchases`, بادئة `PUR-`)
```json
{
  "orderNumber": "PO-...", "supplierName": "...", "submittedBy": "...",
  "purchaseItems": [ { "rowId": "uuid", "itemId": "uuid", "item": "دجاج", "unit": "kg",
                       "ordQty": 10, "rcvQty": null,
                       "unitPriceHalalas": 2500, "orderedUnitPriceHalalas": 2500 } ],
  "supersedesOperationId": "uuid (اختياري)"
}
```
- جدول مطابقة: المطلوب (`ordQty`) مقابل المستلم (`rcvQty`؛ `null` = لم يُستلم بعد → «—»).
- لو `supersedesOperationId` موجود → بانر «نسخة معدّلة من عملية مرفوضة» برابط للعملية القديمة.
- تعديل سطر: `PATCH /operations/{id}/purchase-lines/{rowId}`.
- حوار الرفض يتطلب سبب إلزامي — السبب يصل تلقائيًا لتطبيق الموبايل (لا شغل إضافي عليك).

### جرد (`moduleKey=inventory`, بادئة `INV-`)
```json
{
  "inventoryDate": "2026-07-30",
  "items": [ { "itemId": "uuid", "name": "لحمة", "unit": "kg", "actualQty": 12.5,
               "purchases": null, "waste": null, "expectedQty": null } ]
}
```
`purchases/waste/expectedQty` تكون `null` حتى يُعتمد الجرد في الموبايل (تتملأ تلقائيًا بعدها) → اعرض «—».

### هدر (`moduleKey=waste`)
`payload.products[]`: `{ itemId?, name, qty, value (هللة), classification?, responsibility? }` — و`amount` الإجمالي = مجموع `value`.

## 4) شاشة «تحديد الأصناف للجرد» (فلو جديد كامل)

```
GET /accountant/inventory/brands
→ [ { "id", "name", "abbr", "branchCount": 3, "itemCount": 42 } ]        // بيلز العلامات

GET /accountant/inventory/brands/{brandId}/branches
→ [ { "id", "name", "restaurantId", "listItemCount": 15 } ]              // بيلز الفروع

GET /accountant/inventory/catalog?brandId=&category=&search=
→ { "categories": ["لحوم", ...], "items": [ { "id", "name", "cat", "unit" } ] }

GET /accountant/inventory/branches/{branchId}/daily-list
→ { "data": [ { "id", "catalogItemId", "name", "isFlagged" } ] }          // القائمة الحالية للفرع

PUT /accountant/inventory/branches/{branchId}/daily-list
   { "items": ["catalogItemId1", "catalogItemId2", ...] }                 // استبدال كامل
→ { "savedCount": 5, "pushedAt": "..." }                                  // التطبيق يتنبه فورًا

POST /accountant/inventory/catalog  { "brandId", "name", "category", "unit" }   // إضافة صنف واحد
PUT  /inventory/catalog  { "branchId", "items": [ { "name","category","unit" } ] } // إنشاء + ربط دفعة
```

- قائمة علامات فاضية → empty state «لا توجد علامات في نطاقك» (الرد 200 + []).
- خطأ `422` بكود `BRANCH_UNLINKED` → اعرض `messageAr` («الفرع غير مرتبط بعلامة تجارية…»).
- محاسب العلامة لا يرى إلا علاماته؛ فتح علامة غيره = `404` — لا تعرضها أصلًا.
- شاشات مراجعة الجرد: `GET /accountant/inventory?type=daily|monthly&branchId=` + تعليم:
  `POST /accountant/inventory/branches/{branchId}/flag`، `.../items/flag { itemIndices, note }`، `.../send-confirmation`.

## 5) الأصول الثابتة (محاسب)

```
GET  /accountant/assets
POST /accountant/assets
     { "name", "category", "branchId", "cost": <هللة> (أو priceHalalas), "usefulLifeMonths",
       "invNum"?, "serial"?, "purchaseDate"?, "custodian"?, "notes"? }
POST /accountant/assets/{id}/confirm
GET  /accountant/asset-drafts        POST /accountant/asset-drafts/{draftId}/confirm
```

دورة حالة الأصل — اعرضها كبادجات:
1. `pending_branch` — «بانتظار استلام الفرع» (وصل إشعار حقيقي لموبايل المدير)
2. `pending_accountant` — «الفرع استلم — بانتظار اعتمادك» (تتحول **تلقائيًا** لحظة تأكيد المدير من التطبيق، مع `received_at` واسم المستلم)
3. بعد `confirm` → معتمد نهائيًا.

## 6) بوابة الفرع على الداشبورد (دور `branch`)

```
POST /branch/upload/{reportType}      reportType ∈ sales|expenses|purchases|inventory|waste|cash|shift-close
```
- **هدر**: `products[]` كما في §3 (الإجمالي يُحسب في السيرفر من مجموع `value` — لا ترسل amount).
- **جرد**: `items[]`: `{ itemId, actualQty, name?, unit?, expectedQty?, openingQty?, unitPriceHalalas? }`.
- **مصروفات**: `invoices[]` (نفس شكل §3) — الإجمالي من السيرفر.
- مرفقات multipart في `attachments[]`.
- إضافات: `GET /branch/overview`، `/branch/upload/status`، `/branch/inventory-items`، `POST /branch/assets/{id}/confirm`.

## 7) سطح الهيد

```
GET  /head/operations/pending | final-approved | rejected     (صفوف بنفس شكل §2 + brandName/branchName)
POST /operations/{id}/final-approve | return-for-review       + bulk-final-approve / bulk-return
```

## 8) حسابات الاختبار (كلمة المرور للجميع: `password`)

| الدور | الإيميل |
|---|---|
| هيد | head@nakhat.sa |
| أدمن | admin@nakhat.sa |
| محاسب برجر بيت | accountant.burger@nakhat.sa |
| محاسب شاورما الأصيل | accountant.shawarma@nakhat.sa |
| مشتريات | procurement@nakhat.sa |
| مالك علامة | owner@nakhat.sa |
| بوابة الفرع (§6) | branch@nakhat.sa |

> ملاحظة مؤقتة: روابط `publicUrl` للمرفقات هتشتغل بعد تفعيل storage symlink على السيرفر (شغل باك إند جارٍ) — اعرض placeholder لو الصورة 404 بدل ما تكسر الشاشة.

## 9) قائمة قبول (اختبرها قبل التسليم)

1. دخول محاسب برجر → العمليات تعرض **اسم الفرع والعلامة** (لا UUIDs) وتاريخ كل عملية بيومها الحقيقي (EXP-0011 تظهر بتاريخ 2026-07-23 ومعها مرفق يفتح كصورة).
2. فلتر «هذا الشهر» يعرض عمليات يوليو 2026 (بعد إزالة التاريخ المثبّت).
3. «تحديد الأصناف للجرد»: بيلز العلامات تظهر بعدّاداتها → فروع → أصناف → حفظ القائمة يرجّع `savedCount`.
4. محاسب شاورما لا يرى علامة برجر (ولا العكس).
5. عملية PUR- تعرض جدول الأصناف والكميات والأسعار بالريال + اسم المورد ومقدّم الطلب، والرفض بسبب إلزامي.
6. عملية INV- تعرض جدول الأصناف بالكميات الفعلية، والأعمدة الفارغة «—».
7. إضافة أصل من المحاسب → يظهر `pending_branch`؛ بعد تأكيد المدير من الموبايل يتحول `pending_accountant` تلقائيًا.
8. انتهاء صلاحية التوكن أثناء الاستخدام لا يطرد المستخدم (refresh صامت).
9. كل خطأ من السيرفر يعرض `error.messageAr` وليس رسالة عامة.

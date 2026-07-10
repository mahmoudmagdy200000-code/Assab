

> Branch: `asab-admin-backend` · Base URL: `/api/v1` · Auth: `Authorization: Bearer <token>` (guard `asab`)
> شكل الاستجابة الموحد: `{ "success": true, "message": "...", "data": {...}, "meta": {...} }`
> الأخطاء: `{ "success": false, "message": "...", "errors": {...} }` — كود الخطأ في `errors.code`.

هذا المستند يغطي **فقط** الـ endpoints الجديدة أو اللي اتغير سلوكها في هذه الدفعة. أي endpoint مش مذكور هنا لم يتغير.

---

## 1) الباقات (Packages) — جديد كليًا · دور: admin

كان اختيار الباقة عند إنشاء البراند enum ثابت بأسعار متحطوطة في الكود. دلوقتي فيه جدول + CRUD كامل والفرونت يقرأ منه.

| Method | Path | الوصف |
|---|---|---|
| GET | `/admin/packages` | كل الباقات (نشطة وغير نشطة) — لملء dropdown اختيار الباقة |
| POST | `/admin/packages` | إنشاء باقة |
| PATCH | `/admin/packages/{id}` | تعديل باقة |
| DELETE | `/admin/packages/{id}` | حذف (soft) |

**POST body:**
```json
{ "code": "gold", "name": "ذهبي", "nameEn": "Gold", "price": 175000, "isActive": true }
```
`code` مطلوب فريد، `name` مطلوب، `price` integer بالوحدة الحالية (نفس أرقام الأسعار القديمة: فضي 100000 / ذهبي 175000 / بلاتيني 250000)، `nameEn` و`isActive` اختياري.

**شكل عنصر الباقة في الاستجابة:**
```json
{ "id": "uuid", "code": "gold", "name": "ذهبي", "nameEn": "Gold", "price": 175000, "isActive": true }
```

**ربط شاشة إضافة البراند:** الـ dropdown بتاع الباقة يتملّى من `GET /admin/packages` (اعرض `isActive:true` بس)، والقيمة المبعوتة في `POST /admin/brands` تفضل نفسها في حقل `plan` (بيقبل الأكواد الجديدة + الأسماء القديمة silver/gold/platinum + العربي — كلها شغالة). كود غير موجود في الجدول يرجّع **422**.

---

## 2) قواعد تعيين المستخدمين — سلوك أقوى · دور: admin + company-admin

الاجتماع: **مدير الفرع = فرع واحد بالظبط**، **المحاسب = يتعيّن على براند (مش فرع/مطعم)**. دلوقتي الباك بيفرض ده بالفاليديشن — الفرونت لازم يبعت الحقول صح وإلا **422**.

### `POST /admin/users` — التغييرات في الفاليديشن
| الدور المُرسل (`role`) | حقل مطلوب إضافي | القاعدة |
|---|---|---|
| `branch` | `branches` | array فيها عنصر **واحد بالظبط** (`size:1`) |
| `accountant` | `brands` | array فيها عنصر واحد على الأقل (`min:1`) |
| باقي الأدوار | — | كما هي |

- لو بعت مدير فرع بـ 0 أو 2 فرع → 422.
- لو بعت محاسب من غير `brands` → 422.
- الباك بيثبّت `scope` تلقائيًا (`branch` للفرع، `brand` للمحاسب) — **مفيش داعي الفرونت يبعت scope** لهذين الدورين.

### `POST /company/me/users` (الدعوة) — قاعدة إضافية
عند `roleKey: "accountant"` الحقل `brandId` بقى **مطلوب** (زي ما `branchId` مطلوب للـ branch). ناقص → 422 بكود `INVALID_ROLE_SCOPE`. كمان `brandId` بيتأكد إنه تابع لنفس الشركة، غير كده 422.

### تعيين مدير للفرع
`POST /admin/restaurants/{restaurantId}/branches` و `PATCH /admin/branches/{id}` — لو بعت `managerUserId` لمستخدم **مدير فرع تاني بالفعل** → 422 (منع تعيين نفس المدير لأكثر من فرع).

---

## 3) إضافة موظف/كاشير من الداشبورد → حساب موبايل · دور: branch

قبل كده إضافة موظف كانت بتعمل سجل إداري بس بدون حساب دخول للتطبيق. دلوقتي لو الدور "كاشير" بيتعمل حساب Cashier حقيقي بباسورد مؤقت + إيميل تفعيل.

**Endpoints (بدون تغيير في المسار):**
- `POST /company/me/branch/employees`
- `POST /branch/employees`

**حقول جديدة في الـ body (اختيارية لكن مهمة للكاشير):**
```json
{ "name": "...", "role": "كاشير", "salaryHalalas": 300000, "email": "cashier@x.com", "phone": "05..." }
```
> `POST /company/me/branch/employees` بيستخدم `salaryHalalas` + `shift`. `POST /branch/employees` بيستخدم `empNumber` + `monthlySalary` + `shiftType`. (نفس اللي كان — بس اتضاف `email` و`phone`.)

**متى يتعمل حساب كاشير:** لما `role` يساوي (case-insensitive) `cashier` / `كاشير` / `أمين صندوق`. أي دور تاني = سجل إداري بس.

**الاستجابة بتضيف مفتاح `cashier` لما يتعمل provisioning:**
```json
{
  "id": "emp-uuid", "empNumber": "...", "name": "...",
  "cashier": { "provisioned": true, "cashierId": "cashier-uuid", "reason": null, "emailSent": true }
}
```
`reason` ممكن يكون: `null` (اتعمل جديد)، `LINKED_EXISTING` (اتربط بكاشير موجود بنفس الإيميل)، `RESTORED_EXISTING` (اترجّع كاشير محذوف)، أو لو `provisioned:false` بيبقى `EMAIL_REQUIRED` / `NO_BRANCH_MANAGER`.

**للفرونت:** لو دور كاشير، خلّي `email` مطلوب في الفورم (من غيره السجل بيتعمل بس من غير حساب دخول ويرجّع `reason: "EMAIL_REQUIRED"`). لو الإيميل مستخدم في شركة تانية → 422 بكود `EMAIL_CONFLICT`.

---

## 4) شاشات مدير الفرع (قراءة) — نفس المسارات، بيانات مختلفة/مصلّحة · دور: branch

الـ endpoints دي كانت بتسرّب بيانات شركات تانية أو بتقرأ من مكان غلط. المسارات ثابتة، الأشكال اتوسّعت (superset — مفيش حاجة اتشالت).

### `GET /branch/inventory-items` و `GET /company/me/branch/items`
دلوقتي بترجّع الأصناف **المخصصة لفرعه** (القائمة اللي الأدمن/المحاسب حطها)، ولو مفيش قائمة بيرجع كتالوج البراند (أصناف المبيعات).
```json
{ "items": [ { "id": "uuid", "name": "...", "unit": "...", "cat": "..." } ], "configuredBy": "اسم من ضبط القائمة أو null" }
```

### `GET /branch/suppliers` و `GET /company/me/suppliers`
دلوقتي موردين شركته النشطين بس (كان بيسرّب كل الموردين). عنصر المورد:
```json
{ "id":"uuid","name":"...","category":"...","contactName":"...","contactPhone":"...","contactEmail":"...","paymentTerms":"...","rating":0,"status":"active","isActive":true }
```

### `GET /branch/settings` (و `/company/me/branch/settings`)
اسم الفرع + التليفون + العنوان بقوا **للعرض فقط** من سجل الأدمن، ومعاهم مصفوفة تقول أنهي حقول read-only:
```json
{
  "workingHours": { "open": "08:00", "close": "23:00" },
  "autoCloseShift": false, "cashAlertThreshold": 0,
  "branchName": "...", "phone": "...", "address": "...",
  "readOnlyFields": ["branchName","phone","address"],
  "shiftConfig": { "numShifts": 2, "...": "...", "readOnly": true }
}
```
**للفرونت:** اعرض `branchName`/`phone`/`address` و`shiftConfig` كحقول مقفولة (disabled). في `PATCH settings` لو بعتهم بيتم تجاهلهم بصمت (مش هيرجّع خطأ، بس مش هيتغيروا). التعديلات الجزئية بقت merge — الحقول اللي ماتبعتهاش بتفضل زي ما هي (قبل كده كانت بتتمسح).

---

## 5) عزل المحاسب على مستوى البراند — قد يظهر 404 · دور: accountant

المحاسب المعيّن على براند بقى **مش شايف/مش بيعدّل** بيانات فروع براند تاني في: الشفتات، العهدة، الهالك، الجرد، الأصول، الموظفين. الطلبات على مورد خارج نطاقه بترجّع **404** (نفس شكل المورد غير الموجود).

**للفرونت:** مفيش تغيير في المسارات ولا الأشكال. بس اعرف إن أي `{branchId}` أو `{id}` خارج نطاق المحاسب هيرجّع 404 — تعامل معاه كـ "غير موجود/غير مصرّح" طبيعي. (المحاسب صاحب scope=all أو الـ head مش متأثرين.)

كمان `PATCH /admin/accountants/{accId}/assignments` بقى بيقبل تعيين على **براند** مباشرة:
```json
{ "brands": ["brand-uuid"], "headId": "optional-head-uuid" }
```
لو بعت `brands` بيتحط scope=brand ويتمسح أي restaurant ids قديمة. (لسه بيقبل `restaurants` للتوافق مع القديم.)

---

## 6) أصناف وموردين المشتريات → بيوصلوا للموبايل تلقائيًا · دور: procurement / company-admin

نفس الـ endpoints، بس دلوقتي أي صنف/مورد بيتعمل من الداشبورد بيتزامن للموبايل (يظهر في قوائم التطبيق المفلترة بالمورد أو بمسؤول المشتريات، والمورد يبقى قابل يستقبل طلبات).

- `POST /company/me/procurement/items` — body: `{ "name", "unit", "lastPriceHalalas"|"defaultPriceHalalas", "category?", "supplierId?", "code?" }`
- `PATCH /company/me/procurement/items/{id}` · `DELETE /company/me/procurement/items/{id}` (بيعطّل نسخة الموبايل، مش بيمسحها)
- `POST /company/me/procurement/suppliers` — body: `{ "name", "category?", "contactName?", "contactPhone?"|"phone?", "contactEmail?"|"email?", "commercialReg?", "paymentTerms?", "brandId?" }`
- `PATCH .../suppliers/{id}` · `POST .../suppliers/{id}/toggle-active`

**للفرونت:** لا يوجد تغيير مطلوب في الطلبات/الأشكال — التزامن يحصل في الباك. بس لاحظ إن لما تربط صنف بمورد + سعر، الصنف هيظهر في قائمة الموبايل الخاصة بهذا المورد.

---

## ملاحظات عامة للفرونت
- **مفيش أي endpoint اتشال أو اتغيّر مساره.** كل التغييرات إما endpoint جديد (الباقات) أو حقول/سلوك متوسّع (superset).
- الحقول المالية بالـ **halalas** (هللة) — زي ما هي.
- الأخطاء الجديدة تتقرأ من `errors.code`: `INVALID_ROLE_SCOPE`, `EMAIL_CONFLICT`, ومنع تعيين مدير لأكثر من فرع.
- بورتال المورد لسه مخفي (feature flag) حسب قرار الاجتماع — متبنيش شاشاته دلوقتي.

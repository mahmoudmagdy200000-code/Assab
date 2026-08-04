# إصلاحات 2026-08-04 — بوابة المورد: الأصناف والأسعار + اسم المورد

ملاحظتان من الاختبار الميداني بعد إنشاء مورد جديد.

---

## 1) لا يمكن إضافة الأصناف والأسعار (لا بالإضافة اليدوية ولا بالإكسيل)

### السبب الأول (وهو سبب رسالة «تعذر الاتصال بالخادم»)

بوابة المورد كلها خلف feature flag:

```php
Route::middleware('asab.role:supplier')->prefix('asab/supplier')->group(function () {
    if (! config('features.asab_supplier_portal')) {
        return;   // ← لا تُسجَّل أي مسارات إطلاقًا
    }
```

والقيمة الافتراضية كانت `false` (أُخفيت في v1 بقرار اجتماع سابق). النتيجة على
السيرفر: **كل** نداء إلى `/api/v1/asab/supplier/*` يرجع 404 — لا 403 — فتعرض
الواجهة رسالة «تعذر الاتصال بالخادم» وتظهر القائمة فارغة.

**ما تم:** القيمة الافتراضية أصبحت `true` (الأمر متروك للـ env للإخفاء مجددًا).

**مطلوب على الإنتاج:** تأكد من `.env` ثم أعد بناء الكاش:

```bash
# في .env
FEATURE_ASAB_SUPPLIER_PORTAL=true

php artisan config:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache
```

للتحقق: `php artisan route:list --path=asab/supplier` يجب أن يعرض مسارات
`items` و`items/import`.

> **ملاحظة:** `route:cache` كان يفشل على السيرفر بالخطأ
> «Unable to prepare route [api/v1/notifications/unread] for serialization.
> Another route has already been assigned name [notifications.unread]» — راجع
> قسم «كاش المسارات» أدناه؛ تم إصلاحه في نفس الدفعة.

### السبب الثاني — لا يوجد مسار رفع إكسيل أصلًا

كان موجودًا `GET items/export` فقط؛ زر Excel في الشاشة لم يكن له endpoint يناديه.

**ما تم — مساران جديدان:**

```
GET  /api/v1/asab/supplier/items/template?format=xlsx|csv
POST /api/v1/asab/supplier/items/import      (multipart: file=xlsx|xls|csv, ≤5MB)
```

رؤوس أعمدة القالب (وهي نفسها التي يقبلها الرفع، مع مرادفات إنجليزية):

| الصنف | الرمز | الوحدة | السعر (ر.س) | الحد الأدنى | الحد الأقصى | مدة التحضير (يوم) | الفئة | متاح |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |

- السعر في الملف **بالريال** (يُحوّل داخليًا إلى هللات).
- «متاح»: نعم/لا، 1/0، true/false — والفراغ يعني متاح.
- **idempotent**: المطابقة على «الرمز» إن وُجد وإلا على «الصنف» داخل كتالوج المورد نفسه، فإعادة رفع ملف مُصدَّر تُحدِّث الأسعار ولا تُكرِّر الأصناف.
- الرد: `{ "itemCount": 12, "errors": [{ "row": 4, "message": "…" }] }`؛ وإن لم يُحفظ أي صف يرجع 422 بـ `UPLOAD_FAILED`.
- كل صنف يمر على نفس جسر النشر (`syncItem`) الذي تمر به الإضافة اليدوية، فيصل إلى قوائم التطبيق.

### السبب الثالث المحتمل — وحدة السعر (تحقق منه في الواجهة)

`POST /asab/supplier/items` يقرأ `price` و`priceHalalas` **بالهللات**. حقل الشاشة
مكتوب «السعر (ر.س)»، فإرسال `price: 20` كان يُخزَّن 20 هللة = 0.20 ر.س.

**ما تم:** حقل صريح جديد `priceSar` (عشري بالريال) في الإنشاء والتعديل، وكل رد
يحمل الثلاثة معًا:

```jsonc
{ "price": 2000, "priceHalalas": 2000, "priceSar": 20 }
```

**للفرونت:** أرسل `priceSar` لحقل «السعر (ر.س)» واعرض `priceSar`. `price`/`priceHalalas`
تبقى بالهللات كما هي للمستهلكين القدامى.

### إن ظهرت 422 بدل النجاح

- `SUPPLIER_RECORD_MISSING` — حساب الدخول غير مربوط بسجل مورد (`asab_suppliers.user_id`). يحدث إذا أُنشئ الحساب بمسار لا يمر بـ `POST /admin/users` بدور supplier.
- `SUPPLIER_RECORD_AMBIGUOUS` (409) — الحساب يملك أكثر من سجل مورد؛ أرسل `supplierId` (متاح الآن في `/auth/me` تحت `supplier.records`).

---

## 2) اسم المورد لم يُحدَّث — الاسم في الأعلى غير صحيح

**السبب:** الترويسة كانت تقرأ اسم **حساب الدخول** (`asab_users.name` من
`/auth/me`)، بينما الاسم التجاري يعيش في `asab_suppliers.name`. تعديل اسم المورد
كان يُحدِّث سجل المورد وسجل الموبايل فقط، فيبقى الاسم القديم معروضًا في الأعلى.

**ما تم:**

1. `/api/v1/auth/me` صار يحمل هوية المورد التجارية (تُقرأ حيّة من `asab_suppliers`):

```jsonc
{
  "id": "…", "name": "مسؤول المورد",       // ← اسم الشخص صاحب الحساب
  "supplier": {
    "id": "…",
    "name": "شركة الدواجن الوطنية",        // ← اسم يُعرض في الترويسة
    "category": "…", "companyId": "…", "status": "active",
    "records": [{ "id": "…", "name": "…", "status": "active" }]
  }
}
```

**للفرونت:** اعرض `supplier.name` في ترويسة بوابة المورد و`name` في بطاقة المستخدم أسفل القائمة.

2. `PATCH /company/me/suppliers/{id}` صار يُحدِّث اسم حساب الدخول أيضًا — لكن **فقط** إذا كان مطابقًا للاسم القديم للمورد (أي منسوخًا عنه عند الإنشاء). حساب باسم شخص لا يُلمس.

---

---

## 3) كاش المسارات كان يفشل — `route:cache`

```
Unable to prepare route [api/v1/notifications/unread] for serialization.
Another route has already been assigned name [notifications.unread].
```

**السبب:** كل ملف `Modules/*/routes/api.php` يُسجَّل **مرتين** عن قصد:

1. من `RouteServiceProvider` الخاص بالموديول تحت البادئة `/api/v1`،
2. من `routes/api.php` داخل مجموعة `apilocale` تحت `/api` (أُضيفت في إصلاح
   2026-07-31 حتى لا تختفي 14 موديولًا على المضيف الحساس لحالة الأحرف).

فكل `->name()` داخل تلك الملفات (≈400 اسم) كان مُعلنًا مرتين. لارافيل يتسامح مع
ذلك أثناء التشغيل لكن `route:cache` يرفض التسلسل — فبقي الإنتاج بلا كاش مسارات
أصلًا، ومعه تُدفع كلفة تجميع ~1500 مسار في كل طلب.

**ما تم:** النسخة الثانية (نسخة `apilocale`) صارت تأخذ بادئة أسماء:

```php
Route::name('apilocale.')->group(fn () => require $file);
```

فتبقى الأسماء المجرّدة (`notifications.unread`) للنسخة القانونية تحت `/api/v1`،
ولا يتغيّر أي مسار URL. اختبار `tests/Feature/RouteCacheableTest.php` يمنع تكرار
أي اسم مستقبلًا.

**على الإنتاج:**

```bash
php artisan route:clear && php artisan route:cache   # ينجح الآن
php artisan config:clear && php artisan config:cache
```

> بما أن المسارات ستصبح مُخزَّنة، أي تغيير في `FEATURE_ASAB_SUPPLIER_PORTAL`
> يتطلب `route:clear && route:cache` من جديد.

---

## الاختبارات

`tests/Feature/SupplierCatalogUploadTest.php` — 8 اختبارات: تسجيل المسارات، سعر
بالريال، الرفع بالإكسيل، إعادة الرفع تُحدِّث ولا تُكرِّر، رفض ملف بلا عمود «الصنف»،
تنزيل القالب، هوية المورد في `/auth/me`، وانتقال إعادة التسمية إلى البوابة.

Regression: 74 اختبارًا في سويتات المورد الحالية (بما فيها اختبار «البوابة مغلقة»
الذي يجبر الـ flag على false) — كلها خضراء. Pint نظيف.

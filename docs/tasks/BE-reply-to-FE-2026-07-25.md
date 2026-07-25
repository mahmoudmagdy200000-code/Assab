# رد الباك اند على handoff الفرونت — 2026-07-25 (BUG-5 / 6 / 9 + القرارات)

> **من:** الباك اند · **إلى:** الفرونت · **رد على:** ردكم بتاريخ 2026-07-23.
> كل نقطة تحت مأكّدة بالكود (file:line). الخلاصة: **مفيش تغيير باك اند مطلوب** لـ CORS/frontend_url (متعملين)، و**اتنين من التلات بجّات أعراضهم في ربط الفرونت مش في الباك اند**.

---

## ملخص في سطر لكل بند
| البند | الحالة | الأكشن |
|---|---|---|
| BUG-5 نطاق/صلاحيات | كل مسارات الحفظ **بتثبت**؛ العرض القديم كان BUG-4 (اتصلح) | فرونت: أعيدوا الاختبار بعد BUG-4 |
| BUG-6 موظفين | الحفظ **بيثبت** في `asab_employees` | فرونت: اقروا الحالة من endpoint المطعم مش البراند |
| BUG-9 موردين/مواد خام | **اتربطوا** بجداول الموبايل (2026-07-25) | — |
| BUG-9 أصناف مبيعات | **اتربطت** — الرفع بيعمل صفوف `categories` (2026-07-25) | فرونت: بيكر المصروفات هيتملّى |
| CORS + frontend_url | **متعملين بالفعل** | ops: حطّوا env في prod |

---

## 1. BUG-5 — تراجع النطاق/الصلاحيات

مسارات الحفظ الي بتستخدموها **كلها بتثبت وبترجع محدّثة**:

- `PATCH /api/v1/admin/accountants/{accId}/assignments` → `DistributionController::assignments` — بيحفظ `brand_ids`/`restaurant_ids`/`scope` جوه `DB::transaction` ويرجّع الحالة الطازجة. `DistributionController.php:118-166`
- `PUT /api/v1/admin/accountants/{accId}/restaurants/{restaurant}/modules` → `restaurantModules` — بيحفظ `restaurant_ids`+`module_keys`. `DistributionController.php:180-207`
- `PUT /api/v1/admin/permissions` → `PermissionMatrixController::replace` — `updateOrCreate` جوه transaction، وبيرجّع `index()` (المصفوفة المحدّثة). `PermissionMatrixController.php:76-113`
- `GET /api/v1/admin/distribution` بيشتق التغطية **عبر البراندات** ويرجّع `assignedRestaurants` / `restaurantsNamed` المحدّثة — **ده بالظبط إصلاح BUG-4**. `DistributionController.php:23-73`

**الخلاصة:** ولا مسار من دول بيمسح بيانات. عرض «صفر مطاعم» بعد الحفظ كان **BUG-4** (التغطية كانت تتقري من `restaurant_ids` الفاضية بدل الاشتقاق عبر البراند) — واتصلح. **يعني BUG-5 اتحل ضمنيًا مع BUG-4.** كمان أكّدتوا إن زر «تعديل الصلاحيات» في شاشة المستخدمين NO-OP — فتحذيرنا القديم عن `PATCH /admin/users` **مش بيتستَثار من واجهتكم** أصلاً.

**repro المتوقّع دلوقتي:** توزيع → تخصيص مطعم لمحاسب → حفظ → refresh → `GET /admin/distribution` هيظهره في `assignedRestaurants`/`restaurantsNamed`. **لو لسه بيترجع** على شاشة معيّنة، ابعتوا الـ endpoint + الـ request/response بالظبط — لأن الكود مابيمسحش.

---

## 2. BUG-6 — الموظفون لا يُحفظون

**الحفظ بيثبت فعلاً** (مش بيتمسح):
- `POST /admin/restaurants/{restaurantId}/upload/employees` → `employees()` → `importEmployeeRow` → `Employee::create` في **`asab_employees`** (مربوط بالـ `branch_id`) + لوجين موبايل لصفوف الكاشير — كله جوه `DB::transaction`. `UploadController.php:515-573, 610-640`
- الحالة بتتختم بـ `stampStatus('restaurant', restaurantId, 'employees', …)` — `owner_type='restaurant'`، و`updateOrCreate` (مفيش صفوف مكررة على re-upload). `UploadController.php:569, 868-884`

**جذر المشكلة (ربط فرونت):** انتوا بتقروا الاكتمال من `GET /admin/brands/{brandId}/upload-status` (`status`) — ده بيقرا **`owner_type='brand'` بس**، وخطواته `['sales-items','raw-materials','suppliers','fixed-assets']` — **الموظفين مش ضمنها إطلاقًا**. `UploadController.php:447-474`. فبيظهر دايمًا «لم يُرفع» = بالظبط عرض «التراجع بعد refresh».

**الحل (فرونت):** اقروا حالة الموظفين من **`GET /api/v1/admin/restaurants/{restaurantId}/upload-status`** (`restaurantStatus`) — بيرجّع `employees: true` + `completionPct: 100` بعد رفع ناجح. `UploadController.php:579-597`

**ملاحظة مهمة:** صف موظف باسم فرع **مش موجود تحت المطعم** بيترفض كـ row-error (مش بيتحفظ بـ branch فاضي — عمداً). لو كل الصفوف اترفضت → `count=0` → `status='failed'` → `employees:false`. اتأكدوا إن عمود «اسم الفرع» في الشيت مطابق لفرع تحت **نفس** المطعم. `UploadController.php:543-551`

---

## 3. BUG-9 — قوائم الأصناف/المصروفات في الموبايل

اتعمل الأسبوع ده (2026-07-25) — رفع الأدمن بقى **بيكتب في جداول الموبايل**، مش بس جداول الأدمن:

- **الموردون** ✅ — رفع الموردين بيعمل صف legacy `suppliers`. بيكر المصروفات في الموبايل بيقراه: `ExpenseHelperService::getSuppliers` → `Supplier::where('is_active', true)`. `ExpenseHelperService.php:170-187`. (idempotent: إعادة الرفع بتحدّث مش بتكرّر.)
- **المواد الخام** ✅ — الرفع بيزرع `branch_item` لفروع البراند؛ بيكر أوفيسر المشتريات بيقرا branch_item.

**بخصوص أسئلتكم بالظبط:**

**(أ) هل قائمة الأصناف في الموبايل تقرأ من نفس كتالوج sales-items المرفوع؟**
**أيوه دلوقتي (2026-07-25).** الرفع لسه بيخزّن الصف في `asab_inventory_catalog` (كتالوج الأدمن)، **بس كمان** بقى بيعمل صف في جدول `categories` بتاع الموبايل من عمود «التصنيف»، فبيكر المصروفات في الموبايل بيتملّى. التفاصيل تحت في «القرار المطلوب — اتنفّذ».

**(ب) مصدر «المصروفات» في الموبايل بالظبط:**
موديول Expense في الموبايل بيقرا من:
- **الكاتيجوريز/الأصناف** → جدول **`categories`** (`Modules\Expense\Models\Category`، `categories.type` = `expense` أو `purchase`) عن طريق `getCategories` / `getParentCategories`. `ExpenseHelperService.php:17-74`. **مش من رفع الأدمن.**
- **الموردين على المصروف** → جدول **`suppliers`** (legacy) — ده الي اتربط دلوقتي بالرفع.
- **مفيش «رفع مصروفات»** — الكاتيجوريز بتتدار من CRUD الكاتيجوري / seeder، مش من `upload/sales-items`.

**القرار المطلوب — اتنفّذ (2026-07-25):** رفع الأدمن بقى بيعمل صفوف `Modules\Expense\Category` من عمود «التصنيف» في الشيت:
- **الـ granularity:** كاتيجوري-لكل-تصنيف (مش صنف-لكل-صف) — كل قيمة فريدة في عمود «التصنيف» بتبقى صف `categories` واحد. صفوف كتير بنفس التصنيف بتتجمّع تحت كاتيجوري واحد (dedup بـ `(name, type)`).
- **الـ type map:** `sales-items → type='expense'` (بيغذّي تبويب «المصروفات»)، `raw-materials → type='purchase'` (بيغذّي تبويب «الأصناف/المشتريات»). مصدر الـ type هو **نوع الرفع** — الشيت مافيهوش عمود expense/purchase.
- **idempotent + create-only:** إعادة الرفع مابتكرّرش؛ التصنيف الفاضي بيتتخطى.
- **تنبيه:** جدول `categories` **global** (مفيهوش عمود brand/company وبيكر الموبايل بيقراه unscoped)، فالبراندات بتتشارك نفس الـ taxonomy (بيطابق «تصنيف واحد» بتاع الميتنج). عزل per-brand حقيقي محتاج عمود tenant على `categories` + scoping للقراءة — بره النطاق دلوقتي.

الكود: `Modules\Admin\Services\ExpenseTaxonomyBridgeService::syncCategoryFor`، متوصّل في `UploadController::importCatalogRow`. تغطية: 3 تستات في `AdminBrandUploadTest` (expense-type، purchase-type، idempotent+blank).

---

## 4. القرارات

1. **مستوى الموظفين = مطعم** ✅ — تمام، مفيش تغيير باك اند. الحفظ في `asab_employees` (branch-keyed داخل فروع المطعم)، القراءة من `restaurantStatus`، وصفوف الكاشير بتاخد لوجين موبايل. ده بيفكّ BUG-6 (بعد تعديل الـ endpoint في §2). ملاحظة: الموظفين حاجة والأصناف حاجة تانية — الموظفين بيغذّوا roster الكاشير/الشفتات مش بيكر المصروفات؛ جزء الأصناف من BUG-9 اتحل لوحده عبر ربط `categories` (§3).
2. **الأصول الثابتة (`branch_id = null`):** المرفوعة على مستوى البراند بتقعد `null` «بانتظار التخصيص» عمداً. `UploadController::brandFixedAssets:275-291`. فيه endpoint تعديل أصل (`PATCH /company/me/assets/{id}` → `AccountantCompanyController::updateAsset`) يقدر يحمل التخصيص لفرع. **توصيتنا:** ابنوا شاشة تخصيص بسيطة — من غيرها الأصول المرفوعة على مستوى البراند **مش هتظهر في شاشات الفروع أبداً**. (قرار product نهائي عندكم.)
3. **مصدر المصروفات:** موضّح في §3.

---

## 5. تأكيدات الباك اند (متعملة بالفعل — مفيش كود جديد)

- **CORS:** `config/cors.php` بالفعل بالشكل الي طلبتوه — `supports_credentials => false`، والـ `allowed_origins` من env: `CORS_ALLOWED_ORIGINS` → fallback `FRONTEND_URL` → `*` (local dev بس). `config/cors.php:16-47`. **مفيش تغيير كود** — بس **ops لازم يحطّوا `CORS_ALLOWED_ORIGINS` (أو `FRONTEND_URL`) في env الـ prod** بدومين الفرونت (عشان ماتفضلوش على `*`).
- **`frontend_url`:** موجود بالفعل في `config/app.php:69` → `'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000')`. معلوماتكم إنه «بيرجع null» **قديمة** — المفتاح اتضاف. **ops لازم يحطّوا `FRONTEND_URL` في env الـ prod** عشان روابط كلمة المرور في الإيميلات تبقى صح.

---

## أكشن مختصر
**فرونت:**
- BUG-6 → بدّلوا قراءة حالة الموظفين لـ `GET /admin/restaurants/{restaurantId}/upload-status`.
- BUG-5 → أعيدوا الاختبار بعد BUG-4؛ لو لسه بيترجع ابعتوا endpoint+payload.
- BUG-9 sales-items → **اتربطت** — تأكّدوا إن بيكر المصروفات في الموبايل بيتملّى بعد رفع sales-items (`GET .../expenses/categories/parent-categories?type=expense`).

**ops:** حطّوا `FRONTEND_URL` + `CORS_ALLOWED_ORIGINS` في env الـ prod.

**باك اند:** BUG-9 كله (موردين + مواد خام + أصناف مبيعات) اتربط بجداول الموبايل. مفيش شغل باك اند متبقّي على BUG-9.

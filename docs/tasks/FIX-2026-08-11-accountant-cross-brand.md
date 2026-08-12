# 2026-08-11 — تعيين مطعم من علامة جديدة لمحاسب لم يصل إليه

## البلاغ

1. إضافة علامة تجارية جديدة («علامة المحمدى»).
2. تعيين مطعمها («مطعم 1») للمحاسب «نزار عبد القادر».
3. صفحة المستخدمين أضافت المطعم فقط ولم تضف العلامة، وحساب المحاسب لم يجد لا العلامة
   الجديدة ولا المطعم.

## السبب

`POST /admin/brands` لا يقبل شركة: كل علامة **تُنشئ شركة خاصة بها**
(`BrandCompanyResolver::resolveFor(null, …)`). يعني «العلامة هي الشركة» فعليًا.

نتيجة ذلك:

- تعيين المطعم كان يكتب `asab_user_roles.restaurant_ids` فقط، فبقي عمود «العلامات
  التجارية» على العلامة القديمة.
- المحاسب مربوط بـ `asab_users.company_id` **واحد**، وكل قراءة كانت تُفلتر بها:
  `BelongsToTenant`، `TenantBranchResolver`، `AsabController::assignedBrandIds()`
  (`array_intersect` مع براندات الشركة)، و`where('company_id', $user->company_id)`
  في عشرات نقاط النداء.

فالتعيين كان يُحفظ ثم يصبح **ميتًا بلا أي رسالة خطأ**.

## الحل

المحاسب صار يتبع **مجموعة شركات** = شركته + شركات ما أُسند إليه فعلًا:

| الملف | التغيير |
|---|---|
| `Support/TenantContext.php` | `$companyIds` + `companyIds()` (الأساسية أولًا) |
| `Services/TenantCompanyResolver.php` | جديد — يشتق الشركات من `brand_ids` / `restaurant_ids` |
| `Http/Middleware/ResolveTenant.php` | يملأ `companyIds`، ويشتق `companyId` لو كان NULL |
| `Models/Concerns/BelongsToTenant.php` | `whereIn` بدل `where` |
| `Services/TenantBranchResolver.php` | `whereIn('asab_company_id', …)` |
| `Http/Controllers/AsabController.php` | `tenantCompanyIds()` / `tenantCompanyIdsFor()` / `scopeToTenantCompanies()` |
| واجهات المحاسب + خدماتها | كل `where('company_id', $user->company_id)` صار `whereIn` على المجموعة |
| `Services/AccountantScopeService.php` | `brandIdsForAssignment()` = `brand_ids` ∪ براندات `restaurant_ids` |
| `Admin/UserController` + `Admin/DistributionController` | العرض يستخدم التغطية الفعلية (`brandsNamed` / `coveredBrands` / `brandCount`) |
| `Admin/UserController::resolveAssignedCompanyId` | رُفع `BRANDS_SPAN_COMPANIES` — علامتان = شركتان، والعمود يحفظ شركة أول علامة |

التوسعة **مقيدة بالإسناد الصريح**: علامة لم تُسند لا تظهر، و`whereRaw('1 = 0')`
للحساب بلا شركة كما هو.

### لماذا لم نكتب العلامة في `brand_ids`

`AccountantScopeService::restaurantsForAssignment()` يعمل OR بين `brand_ids`
و`restaurant_ids`، فكتابة العلامة كانت ستمنح المحاسب **كل** مطاعم تلك العلامة،
وإلغاء تعيين المطعم الواحد لن يسحب التغطية. لذلك العلامة **مشتقة للعرض** لا مكتوبة.

## للواجهة الأمامية

`GET /admin/users` و`GET /admin/distribution` — مفاتيح جديدة/محدّثة على صف المحاسب:

| المفتاح | المعنى |
|---|---|
| `brands` | كما هو — الـ ids **المخزّنة** (هي التي ترسلها الـ PATCH) |
| `coveredBrands` | **جديد** — ids العلامات التي يغطيها فعلًا (المخزّنة + علامات مطاعمه) |
| `brandsNamed` | صار يحمل التغطية الفعلية بالأسماء — **اعرض هذا** |
| `brandCount` | عدد التغطية الفعلية |

قاعدة العرض: اعرض `brandsNamed`، وأرسل `brands` عند التعديل.

## الاختبارات

`tests/Feature/AccountantCrossBrandAssignmentTest.php` — 6 حالات: العرض في صفحة
المستخدمين وشاشة التوزيع، ظهور العلامة والفرع في بوابة المحاسب، بقاء علامة غير
مُسندة مخفية (404)، وسحب التغطية عند إلغاء التعيين.

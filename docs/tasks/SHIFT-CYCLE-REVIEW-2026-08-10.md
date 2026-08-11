# مراجعة دورة الشفتات كاملة + فيكس «الفلترة بالفرع بتُسقط مدير الفرع»

**التاريخ:** 2026-08-10
**النطاق:** داشبورد المحاسب ↔ تطبيق مدير الفرع ↔ تطبيق الكاشير

---

## 1. خريطة الدورة (كما هي فعلاً في الكود)

```
[المحاسب — داشبورد]
  PUT  /company/me/brands/{brandId}/shift-config        ← عدد الشفتات + المدة + بداية أول شفت + الرصيد الافتتاحي
  PUT  /company/me/branches/{branchId}/shift-config     ← override لفرع واحد (يغلب على البراند)
  POST .../shift-config/regenerate
        │  ShiftConfigService (حساب النوافذ) → ShiftScheduleBridgeService
        ▼
  جدول `shifts` (قوالب الشفتات في عالم الموبايل)  — natural key = (branch_id, start_time, end_time)
        │  القوالب اللي خرجت من الجدول الجديد تتعمل is_active = false (ما تتحذفش، عشان التاريخ)
        ▼
[مدير الفرع — الموبايل]
  POST /branch-manager/cashiers            ← إنشاء كاشير + إسناد قوالب شفتات
        │  CashierService::assignShiftsToCashier  → صفوف `cashier_shifts` (يوم × قالب)
  shifts:renew-week (أحد 00:05)            ← يكرّر نمط الأسبوع الحالي على الأسبوع القادم
        ▼
[الكاشير — الموبايل]
  GET  /cashier/my-shifts/pending → POST /cashier/shifts/{id}/start
        │  CashierShiftObserver → ShiftStartedEvent → BridgeLegacyShiftStart → LegacyShiftMirror::open()
        ▼                                            (صف `asab_shifts` status=active → تبويب «مباشر» عند المحاسب)
  POST /cashier/shifts/{id}/end | end-with-handover
        │  ShiftEndService → status=completed
        │  CashierShiftObserver → ShiftEndedEvent → BridgeLegacyCashierShift
        │        └─ يكمّل نفس صف المرآة → ShiftCloseService::close() → عملية SHF- (origin=mobile)
        ▼
  تسليم العهدة: كاشير → كاشير التالي، أو → مدير الفرع (آخر شفت في اليوم)
        ▼
[مدير الفرع] /branch-manager/workday/daily-close/submit
        │  DailyReportSubmittedEvent → BridgeManagerDailyClose → عملية sales (origin=mobile)
        ▼
[المحاسب ثم الرئيس] اعتماد العملية
        │  موافقة نهائية → ShiftCloseService::onFinalApproved → خصم فرق الكاش على الكاشير + الشفت closed
        │  رفض → onRejected → الشفت يرجع active
        └─ ShiftFeedbackBridgeService → يكتب review_status على `cashier_shifts` (مش status)
```

الدورة نفسها **سليمة معمارياً** — كل الجسور موجودة والاتجاهين مربوطين. المشاكل كانت في التفاصيل التالية.

---

## 2. المشاكل اللي اتصلحت في هذه المراجعة

### 2.1 «الرصيد الافتتاحي» ما كانش بيوصل للموبايل أبداً ⭐

**الشاشة:** «Opening balance: Not yet recorded» على كل شفت.

المحاسب بيحدد `openingFloatHalalas` في «تعيين الشفتات»، والقيمة كانت بتتخزن في
`asab_brand_shift_configs.shifts` **وتقف هناك**. كل مسار بينشئ `cashier_shifts` كان بيكتب
`opening_balance = 0` بالثابت (`CashierService`, `ShiftWeekRenewalCommand`, الـ Observer)، فالرقم
المعتمد ما وصلش أي كاشير، والوحيد اللي كان بيملأ الخانة هو تسليم عهدة من كاشير لكاشير.

**الحل:** الرصيد الافتتاحي بقى خاصية للقالب نفسه:

- migration جديد: `shifts.opening_float` (decimal 12,2 — بالريال، وحدة العمود المقابل في الموبايل).
- `ShiftScheduleBridgeService` بيكتبه مع كل regenerate (halalas ÷ 100).
- `CashierShiftObserver::creating()` بيأخذ منه الافتراضي بدل الصفر. التسليم لسه بيغلب لأنه بيحصل بعدين.

> **حساب الفروقات ما تأثرش:** `expectedCash = float + (sales − card − aggregators)` و
> `cashActual = cash_collected + float`، فالـ float بيتشال من الطرفين — مافيش عجز وهمي.
> **مطلوب بعد الديبلوي:** ضغطة «إعادة توليد» (أو `regenerate`) لكل براند/فرع عشان القوالب الحالية
> تاخد قيمتها؛ الشفتات المُنشأة قبل كده تفضل بصفر.

### 2.2 شفت مُعاد إسناده كان بيختفي من تبويب «Pending» عند الكاشير المستلم ⭐

`CashierShiftController::pendingShifts` كان بيفلتر `status = not_started` فقط، بينما إعادة الإسناد
بتحوّل الحالة إلى `reassigned`. قائمة مدير الفرع (`CashierShiftRepository::getUpcomingPaginated`)
كانت دايماً بتستخدم الاثنين — فالكاشير كان بيشوف شفت أقل من اللي مديره شايفه.

**الحل:** الفلتر بقى `whereIn([not_started, reassigned])`، و`status_label` بقى مشتق من الحالة بدل
نص ثابت «Not Started».

### 2.3 ثغرة صلاحيات: إعادة إسناد شفت لكاشير من فرع تاني 🔒

`POST /branch-manager/shifts/{id}/reassign` كان بيتحقق من `exists:cashiers,id` بس — مافيش تحقق من
الفرع. يعني كان ممكن تسليم درج الفرع لكاشير مش تابع له. المسار المقابل
(`reassign-with-handover`) كان بيتحقق فعلاً؛ المسار ده بس كان ناقص.

**الحل:** 403 لو `newCashier->branch_id !== manager->branch_id`.

### 2.4 «Next Cashier» كان بيرجع دايماً مدير الفرع 🔴

`ShiftService::getNextShiftCashier` كان بيقارن:

```php
->where('shifts.start_time', '>=', $endTime)   // $endTime = Carbon
```

`Shift::$casts` فيها `end_time => 'datetime:H:i'`، فالقيمة Carbon والـ binding بيتحول إلى
`'Y-m-d H:i:s'` كامل، بينما العمود فيه `'12:00'` (لما يتكتب عبر الموديل) أو `'12:00:00'` (لما يتكتب
عبر جسر الـ regenerate). المقارنة النصية ما بتطابقش أي صف → **مافيش شفت تالي أبداً** → الواجهة
بتعرض مدير الفرع كمستلم لكل شفت (زي ما ظاهر في الشاشة: «Next Cashier: … (Branch Manager)»).

**الحل:** المقارنة بقت في PHP بعد تطبيع الوقت لدقائق (`minutesOf()`)، فبتشتغل مع الشكلين. كمان
الحالة `reassigned` دخلت ضمن المرشحين — شفت اتنقل لكاشير تاني لسه هو الشفت التالي.

> باقٍ كتحفّظ موثّق: قاعدة «الشفت اللي بينتهي 06:00 هو آخر اليوم» لسه مثبتة بالكود. صحيحة لجدول
> النموذج (4 × 6 ساعات)، وممكن تديّ نتيجة غلط لجدول بمدة مختلفة. اتسابت زي ما هي عشان تغييرها
> يغيّر سلوك التسليم — راجع القسم 3.

### 2.5 `shifts:auto-end-overdue` كان بيقع من أول شفت 🔴

الأمر المجدول (كل ساعة) كان بينفّذ:

```php
$shift->shift->branch->manager->notify(new ShiftAutoEndedNotification($shift));
```

- `branches.manager` عمود **نصي** (اسم المدير) مش علاقة → `Error`.
- `ShiftAutoEndedNotification` كلاس **غير موجود** → `Error`.
- و`catch (\Exception)` **لا يلتقط `Error`**.

النتيجة: أول شفت متأخر بيتقفل ويتبرّدج للداشبورد، وبعدين الأمر بيموت — وباقي الدروج تفضل مفتوحة
وكل ساعة يتكرر نفس الانهيار.

**الحل:** الإشعار بقى للـ `branch_managers` الحقيقيين عبر `BaseNotification` (IN_APP، best-effort)،
والـ catch بقى `\Throwable` مع تسجيل الخطأ، فباقي الشفتات بتكمّل.

### 2.6 تجديد الأسبوع كان بيكرّر قوالب ميتة 🔴

`shifts:renew-week` بياخد نمط الأسبوع الحالي (cashier_id × shift_id) ويكرّره. لما المحاسب يغيّر
الجدول، الجسر **يعطّل** القوالب القديمة (`is_active=false`) بدل ما يحذفها — والتجديد كان بيفضل
يولّد شفتات على القوالب المعطّلة، يعني التطبيق يعرض المواعيد القديمة للأبد مهما حفظ المحاسب.

**الحل:** التجديد بيتخطى القوالب المعطّلة وبيطبع/يسجّل تحذير بعددها. (النقل التلقائي للقالب الجديد
لسه مفتوح — القسم 3.)

### 2.7 أسبوع الإنشاء ≠ أسبوع التجديد

`CashierService::assignShiftsToCashier(forFullWeek: true)` كان بيعمل **7 أيام متتالية** من تاريخ
المرجع، بينما `shifts:renew-week` بيلتزم بأيام العمل من `config('shift.week.work_days')`
(أحد–خميس افتراضياً) ويستثني الإجازات. النتيجة: الكاشير بياخد شفتات جمعة/سبت أول أسبوع بس وبعدها
لأ.

**الحل:** الاثنين بقوا على نفس القاعدة (`ShiftHelper::workWeekDatesExcludingHolidays`، من تاريخ
المرجع فما فوق).

> لو الفروع بتشتغل 7 أيام، الضبط الصحيح بقى `SHIFT_WORK_DAYS=0,1,2,3,4,5,6` في `.env` — مش تفرّع
> في الكود.

### 2.8 ثغرة صلاحيات: إسناد قالب شفت من فرع تاني 🔒

نفس الدالة كانت بتعمل `Shift::findOrFail($shiftId)` من غير أي تحقق إن القالب تابع لفرع الكاشير.
اتضاف تحقق صريح.

### 2.9 كشف الموظفين: الفلترة بالفرع بتُسقط مدير الفرع ⭐ (طلب الفرونت)

راجع القسم 4 — ده الجزء اللي الفرونت بعت عنه.

---

## 3. مفتوح — يحتاج قرار منكم (لم يُنفَّذ)

| # | الموضوع | الوضع | التوصية |
|---|---------|-------|---------|
| 3.1 | تغيير الجدول لا ينقل الإسنادات القائمة | «إعادة توليد» بتحدّث القوالب بس. الشفتات المستقبلية (`not_started`) بتفضل على القالب القديم المعطّل، والتجديد بقى بيتخطاها (2.6) — يعني بعد تغيير الجدول لازم تدخّل يدوي | أمر جديد `shifts:remap-assignments {branch}` ينقل الشفتات المستقبلية غير المبدوءة من القالب المعطّل للقالب الجديد بنفس الترتيب (ordinal). عملية كتابة على بيانات حية — محتاجة موافقتكم على قاعدة المطابقة |
| 3.2 | قاعدة «آخر شفت = ينتهي 06:00» | مثبتة بالكود (`ShiftService`) | تُشتق من `firstShiftStart` في الكونفيج بدل الثابت. مؤجّلة لأنها تغيّر لمين تتسلّم العهدة |
| 3.3 | `ShiftEndedEvent` بيتطلق في `updating` مش `updated`/`afterCommit` | الجسر بيكتب `asab_shifts` + عملية SHF قبل ما صف `cashier_shifts` نفسه يتثبّت | لو الحفظ فشل بعدها، يفضل صف مرآة يتيم. النقل لـ `updated` + `afterCommit` يخالف قاعدة المستودع الحالية أقل، بس محتاج مراجعة الـ dedup guards |
| 3.4 | `ShiftNotificationService` كله متعلّق كتعليقات | ملف ميت بالكامل | يتحذف أو يتفعّل — حالياً بيوهم إن فيه إشعارات شفتات وهي مش موجودة |
| 3.5 | `acceptHandoverByCashier` بيدوّر على «الشفت التالي» بـ `cashier_id + date + not_started` بدون فرع ولا ترتيب | ممكن يحط الرصيد الافتتاحي على الشفت الغلط لو للكاشير أكتر من شفت في اليوم | يُقيَّد بنفس الفرع ويُرتَّب بـ `start_time` |
| 3.6 | كل الكنترولرات في موديول Shift بترجع `catch (\Exception) → 500` برسالة الاستثناء الخام | مخالفة لقاعدة «لا تعرض رسالة استثناء للعميل» | تنظيف منفصل — كبير ومش مرتبط بالدورة |

---

## 4. رد على بلاغ الفرونت — «كشف حساب الموظفين: الفلترة بالفرع بتُسقط مدير الفرع»

**التشخيص:** توقعكم كان صح في الجوهر. المدير عنده **ثلاث** أماكن بتسجّل فرعه، والتعيين على الداشبورد
كان بيحدّث اتنين بس:

| المكان | مين بيحدّثه |
|--------|--------------|
| `branches.asab_manager_user_id` | ✅ عند التعيين/النقل |
| `branch_managers.branch_id` (اللي التطبيق بيفلتر بيه) | ✅ `ManagerBranchSyncService` |
| `asab_employees.branch_id` (اللي الكشف بيفلتر بيه) | ❌ **مفيش** — بيتكتب مرة واحدة بس من `asab:repair-employees` |

فالمدير اللي اتنقل بيفضل صفه على الفرع القديم؛ يظهر في القائمة غير المفلترة (باسم الفرع **القديم**)
ويختفي من `?branchId=<الفرع الجديد>`. ومدير اتعمل له provisioning بعد آخر تشغيل للـ repair ما كانش
له صف أصلاً.

**اللي اتعمل:**

1. **عمود ربط دائم:** `asab_employees.asab_user_id` (migration جديد) — الربط كان بالاسم فقط قبل كده.
2. **`ManagerRosterService` جديد:** ينشئ / يتبنّى / **ينقل** صف المدير عشان `branch_id` يبقى دايماً
   الفرع اللي بيديره. مربوط في:
   - `ManagerBranchSyncService::sync()` → يغطّي كل مسارات التعيين (إنشاء فرع، تعديل فرع، نقل مدير،
     تعديل مستخدم)؛
   - `BranchManagerProvisioner` → المدير الجديد يدخل الكشف من أول يوم.
3. **الفلتر:** `?branchId=X` بقى يرجّع `branch_id = X` **أو** الموظف اللي بيدير X فعلاً — فالمدير
   بيظهر حتى على نسخة لسه ما اتعملهاش backfill.
4. **`branchId` / `branchName` في صف المدير** بقوا الفرع اللي بيديره، في القائمة المفلترة وغير
   المفلترة — عشان الـ dropdown والفلترة يتطابقوا زي ما طلبتوا.
5. **`asab:repair-employees`** بقى **ينقل** الصف القائم بدل ما يعمل نسخة مكررة على الفرع الجديد،
   وبيختم `asab_user_id` على الصفوف القديمة.

**اللي محتاج يتنفّذ على السيرفر بعد الديبلوي:**

```bash
php artisan migrate
php artisan asab:repair-employees --dry-run --brand=<BRAND_ID>   # راجع الناتج الأول
php artisan asab:repair-employees --brand=<BRAND_ID>
```

> شغّلوه **مقيّد ببراند أو فرع**، مش unscoped — الديمو والبيانات الحية على نفس القاعدة (قاعدة
> 2026-08-06).

**من ناحية الفرونت:** مفيش أي تغيير مطلوب. العقد ثابت، ونفس الاستدعاء
`GET /company/me/employees?branchId=<UUID>` بقى يرجّع المدير + باقي الموظفين.

---

## 5. الاختبارات

| ملف | يغطّي |
|-----|-------|
| `tests/Feature/EmployeeBranchFilterManagerTest.php` | الفلترة بالفرع بترجّع المدير · صف عالق على الفرع القديم لسه بيظهر بالـ branchId الصح · النقل بيحرّك الصف مش بيكرّره · `transfer-manager` بينقل الكشف مع الفرع |
| `tests/Feature/ShiftCycleFixesTest.php` | الرصيد الافتتاحي من الداشبورد بيوصل لشفت جديد (وصفر لو مش متضبط) · منع إعادة الإسناد لكاشير من فرع تاني · الشفت المُعاد إسناده بيفضل في Pending · «الكاشير التالي» بيتحل صح |

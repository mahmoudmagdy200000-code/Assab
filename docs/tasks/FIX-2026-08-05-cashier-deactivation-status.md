# فيكس 2026-08-05 — «Data truncated for column 'status'» عند حفظ الكاشير

## الخطأ كما ظهر في التطبيق

```
SQLSTATE[01000]: Warning: 1265 Data truncated for column 'status' at row 1
(Connection: mysql, SQL: update `cashier_shifts` set `status` = cancelled,
 `cashier_shifts`.`updated_at` = 2026-08-05 15:59:14
 where `cashier_shifts`.`cashier_id` = 019fd1b0-… and `status` = not_started
 and date(`shift_date`) >= 2026-08-05)
```

## السبب

ثلاث طبقات لا تتفق على اسم حالة واحدة:

| الطبقة | القيم المسموحة |
| --- | --- |
| عمود `cashier_shifts.status` في MySQL | `ENUM('not_started','in_progress','completed','reassigned')` — **لا يوجد إلغاء إطلاقاً** |
| `Modules\Shift\Enums\ShiftStatus` (الكاست في الموديل) | `not_started, in_progress, completed, canceled, reassigned` |
| `HandleCashierDeactivationListener` | يكتب `'cancelled'` (بلامين) — غير موجود في أي منهما |

تعطيل الكاشير يُلغي شيفتاته القادمة، وهذا الـ`update` يمرّ من الـQuery Builder فلا
يمسّه كاست الموديل، فيصل النص كما هو إلى MySQL. مع `strict mode` يرفض السيرفر
الكتابة (1265) فتفشل عملية حفظ الكاشير كلها ويظهر نص SQL خام في شاشة الموبايل. أي
تعطيل لأي كاشير كان مستحيلاً — ليست حالة نادرة.

## الإصلاح

1. **مايجريشن** `Modules/Shift/database/migrations/2026_08_05_000001_widen_cashier_shift_status.php`
   - العمود صار `string(20)` بافتراضي `not_started`، والتحقق يبقى في طبقة التطبيق عبر
     `ShiftStatus` (نفس ما فعلناه مع `custody_request_timeline`) — فإضافة حالة جديدة
     لاحقاً تصبح تغييراً في الكود لا DDL على جدول ساخن.
   - وتُصلح الصفوف التي قد تكون خُزّنت بـ`''` لو كان `strict mode` مُطفأً في نشرة
     سابقة: الكاست يرفع `ValueError` على القراءة، أي 500 في كل شاشة تعرض ذلك الصف.
2. **اللِسنر** يكتب `ShiftStatus::CANCELED->value` بدل النص الحر.
3. **الأوبزرفر** (`CashierObserver`): نقل إطلاق أحداث الحالة من `updating` إلى
   `updated`. كانت الآثار الجانبية (سحب التوكنات + إلغاء الشيفتات) تنفَّذ **قبل**
   كتابة صف الكاشير، فأي فشل بعدها يترك كاشيراً نشطاً بجدول ملغى.

## أثر على الفرونت

قيمة حالة جديدة قد ترجع في شيفتات الكاشير: **`canceled`** (بلام واحدة). الشيفت
الملغى يخرج تلقائياً من `scopeUpcoming` فلا يظهر في «الشيفتات القادمة».

## النشر

```bash
git pull && php artisan migrate      # يغيّر نوع العمود ويُصلح الصفوف الفارغة
```

بدون المايجريشن يظل تعطيل الكاشير فاشلاً على الإنتاج.

## الاختبارات

`tests/Feature/CashierDeactivationCancelsShiftsTest.php` — 6 حالات: الإلغاء يشمل
شيفتات اليوم والمستقبل المعلَّقة، لا يمسّ شيفتاً جارياً أو ماضياً أو مكتملاً، لا يمسّ
كاشيراً آخر، الشيفت الملغى يخرج من «القادمة»، تعديل لا يغيّر الحالة لا يُلغي شيئاً،
وإعادة حفظ كاشير معطَّل ليست انتقالاً جديداً.

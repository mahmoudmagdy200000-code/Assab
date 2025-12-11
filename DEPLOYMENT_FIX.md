# إصلاح مشكلة Case Sensitivity على الخادم

## المشكلة

عند رفع المشروع على هوستنجر (Linux)، ظهرت مشكلة:

```
require(/path/to/Modules/Cashier/Config/config.php): Failed to open stream: No such file or directory
```

## السبب

بعض المجلدات في الـ modules كانت بأحرف كبيرة (`Config`, `Routes`, `Resources`) بينما Laravel Modules يتوقع أحرف صغيرة (`config`, `routes`, `resources`). على Windows، الملفات case-insensitive، لكن على Linux case-sensitive.

## الحل المطبق محلياً

### 1. إنشاء المجلدات الجديدة بأسماء صحيحة:

-   ✅ `Modules/Cashier/config/` (تم إنشاؤه)
-   ✅ `Modules/Cashier/routes/` (تم إنشاؤه)
-   ✅ `Modules/Cashier/resources/` (تم إنشاؤه)
-   ✅ `Modules/BranchManagers/routes/` (تم إنشاؤه)

### 2. تحديث Service Providers:

تم تحديث المراجع في Service Providers لاستخدام المسارات الصحيحة:

-   ✅ `Modules/Cashier/app/Providers/RouteServiceProvider.php` - تم تحديث `Routes/` إلى `routes/`
-   ✅ `Modules/Cashier/app/Providers/CashierServiceProvider.php` - تم تحديث `Config/` و `Resources/` و `Database/` إلى `config/` و `resources/` و `database/`
-   ✅ `Modules/BranchManagers/app/Providers/RouteServiceProvider.php` - تم تحديث `/Routes/` إلى `/routes/`
-   ✅ `Modules/BranchManagers/app/Providers/BranchManagersServiceProvider.php` - تم تحديث `database/Migrations` إلى `database/migrations`

## الخطوات المطلوبة على الخادم

بعد رفع الملفات الجديدة، قم بحذف المجلدات القديمة على الخادم:

```bash
# الانتقال إلى مجلد المشروع
cd /home/u887387254/domains/ivory-snail-183262.hostingersite.com/public_html

# حذف المجلدات القديمة (إذا كانت موجودة)
rm -rf Modules/Cashier/Config
rm -rf Modules/Cashier/Routes
rm -rf Modules/Cashier/Resources
rm -rf Modules/BranchManagers/Routes

# التأكد من وجود المجلدات الجديدة
ls -la Modules/Cashier/config/
ls -la Modules/Cashier/routes/
ls -la Modules/Cashier/resources/
ls -la Modules/BranchManagers/routes/

# تشغيل composer install مرة أخرى
composer install --no-dev
```

## التحقق من الإصلاح

بعد حذف المجلدات القديمة، يجب أن يعمل الأمر التالي بدون أخطاء:

```bash
composer install --no-dev
php artisan package:discover --ansi
```

## ملاحظات

-   على Windows، قد لا تتمكن من حذف المجلدات القديمة بسبب case-insensitivity
-   تأكد من رفع جميع الملفات الجديدة قبل حذف القديمة
-   احتفظ بنسخة احتياطية قبل الحذف

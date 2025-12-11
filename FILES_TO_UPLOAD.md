# الملفات المطلوب رفعها على الخادم

## ⚠️ مهم جداً

بعد إصلاح مشكلة case sensitivity، يجب رفع الملفات التالية على الخادم:

## 1. المجلدات والملفات الجديدة

### Modules/Cashier/

```
Modules/Cashier/
├── config/
│   └── config.php                    ✅ جديد
├── routes/
│   ├── api.php                       ✅ جديد
│   └── web.php                       ✅ جديد
└── resources/
    ├── assets/
    │   ├── js/
    │   │   └── app.js
    │   └── sass/
    │       └── app.scss
    └── views/
        ├── index.blade.php
        └── components/
            └── layouts/
                └── master.blade.php
```

### Modules/BranchManagers/

```
Modules/BranchManagers/
└── routes/
    ├── api.php                       ✅ جديد
    └── web.php                       ✅ جديد
```

## 2. Service Providers المحدثة

يجب رفع جميع Service Providers التالية بعد التحديث:

### Modules/Cashier/app/Providers/

-   ✅ `CashierServiceProvider.php` (تم تحديثه)
-   ✅ `RouteServiceProvider.php` (تم تحديثه)

### Modules/BranchManagers/app/Providers/

-   ✅ `BranchManagersServiceProvider.php` (تم تحديثه)
-   ✅ `RouteServiceProvider.php` (تم تحديثه)

### Modules الأخرى (تم تحديثها أيضاً):

-   ✅ `Modules/Purchase/app/Providers/RouteServiceProvider.php`
-   ✅ `Modules/Aggregator/app/Providers/RouteServiceProvider.php`
-   ✅ `Modules/BrandOwner/app/Providers/RouteServiceProvider.php`
-   ✅ `Modules/Admin/app/Providers/RouteServiceProvider.php`
-   ✅ `Modules/Branch/app/Providers/RouteServiceProvider.php`

## 3. خطوات الرفع

### الطريقة الأولى: رفع يدوي عبر FTP/SFTP

1. ارفع المجلدات الجديدة:

    - `Modules/Cashier/config/`
    - `Modules/Cashier/routes/`
    - `Modules/Cashier/resources/`
    - `Modules/BranchManagers/routes/`

2. ارفع Service Providers المحدثة:
    - جميع الملفات المذكورة أعلاه في قسم "Service Providers المحدثة"

### الطريقة الثانية: استخدام Git

إذا كنت تستخدم Git:

```bash
# على الخادم
cd /home/u887387254/domains/ivory-snail-183262.hostingersite.com/public_html
git pull origin main  # أو branch الخاص بك
```

### الطريقة الثالثة: رفع مجلد Modules بالكامل

إذا كنت تريد التأكد من رفع كل شيء:

```bash
# على الخادم، احذف مجلد Modules القديم (بعد عمل backup)
cd /home/u887387254/domains/ivory-snail-183262.hostingersite.com/public_html
cp -r Modules Modules_backup  # عمل نسخة احتياطية
# ثم ارفع مجلد Modules بالكامل من محلي
```

## 4. التحقق بعد الرفع

بعد رفع الملفات، تحقق من وجودها:

```bash
# على الخادم
cd /home/u887387254/domains/ivory-snail-183262.hostingersite.com/public_html

# التحقق من الملفات الجديدة
ls -la Modules/Cashier/config/config.php
ls -la Modules/Cashier/routes/api.php
ls -la Modules/Cashier/routes/web.php
ls -la Modules/BranchManagers/routes/api.php
ls -la Modules/BranchManagers/routes/web.php

# تشغيل composer
composer install --no-dev
```

## 5. ملاحظات مهمة

-   ⚠️ تأكد من رفع **جميع** Service Providers المحدثة
-   ⚠️ تأكد من رفع **جميع** المجلدات الجديدة (`config/`, `routes/`, `resources/`)
-   ⚠️ لا تنسى حذف المجلدات القديمة (`Config/`, `Routes/`, `Resources/`) بعد التأكد من رفع الجديدة
-   ⚠️ احتفظ بنسخة احتياطية قبل أي تغييرات

## 6. قائمة سريعة للملفات المطلوبة

```
✅ Modules/Cashier/config/config.php
✅ Modules/Cashier/routes/api.php
✅ Modules/Cashier/routes/web.php
✅ Modules/Cashier/resources/ (المجلد بالكامل)
✅ Modules/BranchManagers/routes/api.php
✅ Modules/BranchManagers/routes/web.php
✅ Modules/Cashier/app/Providers/CashierServiceProvider.php
✅ Modules/Cashier/app/Providers/RouteServiceProvider.php
✅ Modules/BranchManagers/app/Providers/BranchManagersServiceProvider.php
✅ Modules/BranchManagers/app/Providers/RouteServiceProvider.php
✅ Modules/Purchase/app/Providers/RouteServiceProvider.php
✅ Modules/Aggregator/app/Providers/RouteServiceProvider.php
✅ Modules/BrandOwner/app/Providers/RouteServiceProvider.php
✅ Modules/Admin/app/Providers/RouteServiceProvider.php
✅ Modules/Branch/app/Providers/RouteServiceProvider.php
```

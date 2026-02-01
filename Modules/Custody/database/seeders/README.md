# Custody Module Seeders

## 📋 الوصف

هذا المجلد يحتوي على seeders لإنشاء بيانات عشوائية لاختبار نظام إدارة العهدة والدفاتر.

## 🚀 الاستخدام

### تشغيل جميع Seeders

```bash
php artisan db:seed --class="Modules\Custody\Database\Seeders\CustodyDatabaseSeeder"
```

### تشغيل Seeder محدد

```bash
# Personal Ledger Transactions
php artisan db:seed --class="Modules\Custody\Database\Seeders\PersonalLedgerTransactionSeeder"

# Custody Requests
php artisan db:seed --class="Modules\Custody\Database\Seeders\CustodyRequestSeeder"

# Custody Transactions
php artisan db:seed --class="Modules\Custody\Database\Seeders\CustodyTransactionSeeder"

# Fix Personal Ledger Balances (make all balances positive)
php artisan db:seed --class="Modules\Custody\Database\Seeders\FixPersonalLedgerBalanceSeeder"
```

## 📊 البيانات المُنشأة

### PersonalLedgerTransactionSeeder

-   **20-30 معاملة** لكل Branch Manager
-   أنواع المعاملات:
    -   Total Sales (إيداع)
    -   Handover to Brand Owner (سحب)
    -   Transfer to Custody (سحب)
-   تواريخ عشوائية خلال آخر 30 يوم
-   مبالغ عشوائية:
    -   الإيداعات: 500 - 5000
    -   السحوبات: 200 - 3000

### CustodyRequestSeeder

-   **5-10 طلبات** لكل Branch Manager
-   حالات مختلفة: Pending, Approved, Rejected, Completed
-   Timeline كامل لكل طلب
-   تواريخ عشوائية خلال آخر 60 يوم
-   مبالغ عشوائية: 1000 - 10000

### CustodyTransactionSeeder

-   **15-25 معاملة** لكل Branch Manager
-   أنواع المعاملات:
    -   Cash Transfer (إيداع)
    -   Cash Handover (إيداع)
    -   Bank Transfer (إيداع)
    -   Expenses Deduction (سحب)
-   ربط تلقائي مع الطلبات المعتمدة والمصروفات
-   تواريخ عشوائية خلال آخر 30 يوم

### FixPersonalLedgerBalanceSeeder

-   **إصلاح الأرصدة السالبة** لجميع Branch Managers
-   يضيف معاملات "Total Sales" (إيداع) تلقائياً إذا كان الرصيد سالب أو صفر
-   يجعل الرصيد موجب على الأقل بقيمة 10000
-   يقسم المبلغ المطلوب على 2-3 معاملات ليكون أكثر واقعية
-   تواريخ عشوائية خلال آخر 5 أيام

## ⚠️ متطلبات

قبل تشغيل Seeders، تأكد من:

1. وجود Branch Managers في قاعدة البيانات
2. وجود Branches
3. (اختياري) وجود Expenses للمصروفات المرتبطة

## 🔄 إعادة تعيين البيانات

لحذف البيانات وإعادة إنشائها:

```bash
# حذف البيانات (احذر: سيحذف جميع البيانات!)
php artisan tinker
>>> \Modules\Custody\Models\PersonalLedgerTransaction::truncate();
>>> \Modules\Custody\Models\CustodyRequest::truncate();
>>> \Modules\Custody\Models\CustodyTransaction::truncate();
>>> exit

# إعادة إنشاء البيانات
php artisan db:seed --class="Modules\Custody\Database\Seeders\CustodyDatabaseSeeder"
```

## 📝 ملاحظات

-   البيانات عشوائية ومخصصة للاختبار فقط
-   التواريخ تتراوح بين اليوم وآخر 30-60 يوم
-   المبالغ عشوائية ضمن النطاقات المحددة
-   يمكن تعديل الأرقام في ملفات الـ Seeders حسب الحاجة

# Purchase Test Data Seeder

## الوصف
Seeder شامل لملء قاعدة البيانات ببيانات اختبار كاملة للـ Purchase Module.

## البيانات التي يتم إنشاؤها

### 1. Branches (4 فروع)
- **Main Branch** - Riyadh (24.7136,46.6753)
- **Jeddah Branch** - Jeddah (21.4858,39.1925) - ~950 km من Riyadh
- **Dammam Branch** - Dammam (26.4207,50.0888) - ~400 km من Riyadh
- **Khobar Branch** - Khobar (26.2041,50.1970) - قريب من Dammam

### 2. Branch Managers (4 مديرين)
- مدير لكل فرع
- بيانات تسجيل دخول: `manager1@assab.com` / `password123`

### 3. Branch Items (8 منتجات لكل فرع)
- Coca Cola 330ml
- Fresh Beef (Premium Grade)
- Chicken Breast (Frozen)
- Tomatoes (Fresh)
- Milk 1L
- Bread (White)
- Rice 5kg
- Olive Oil 1L

### 4. Branch Inventory
- كميات متاحة لكل منتج في كل فرع
- تواريخ انتهاء صلاحية
- حالات التبريد

### 5. Suppliers (5 موردين)
- Fresh Foods Trading Co.
- Al Marai Supplies
- Gulf Meat Supplies
- Bakery Ingredients Ltd.
- Premium Seafood Co.

### 6. Supplier Items
- منتجات متاحة من كل مورد
- أسعار مختلفة (economy, standard, premium)

### 7. Purchase Orders (15-30 طلب)
- طلبات لآخر 3 شهور
- أنواع مختلفة: Direct Supplier, Via Purchasing Officer, Internal Transfer
- حالات مختلفة: Confirmed, Closed, Delivered

### 8. Price History
- سجل أسعار لآخر 3 شهور
- لكل نوع طلب ومورد

## طريقة الاستخدام

### تشغيل الـ Seeder
```bash
php artisan db:seed --class="Modules\Purchase\Database\Seeders\PurchaseTestDataSeeder"
```

### أو من خلال DatabaseSeeder
أضف في `database/seeders/DatabaseSeeder.php`:
```php
$this->call([
    // ... existing seeders
    \Modules\Purchase\Database\Seeders\PurchaseTestDataSeeder::class,
]);
```

ثم شغل:
```bash
php artisan db:seed
```

## ملاحظات مهمة

1. **البيانات الحالية**: الـ seeder يستخدم `updateOrCreate` لذلك لن يحذف البيانات الموجودة
2. **التواريخ**: جميع الطلبات في آخر 3 شهور من التاريخ الحالي
3. **الإحداثيات**: إحداثيات حقيقية للمدن السعودية لحساب المسافات
4. **التنوع**: البيانات متنوعة لتغطية جميع حالات الاختبار

## اختبار الـ API

بعد تشغيل الـ seeder، يمكنك اختبار:

### 1. Compare Prices
```bash
POST /api/v1/purchase/orders/compare-prices
{
    "item_id": "uuid-of-branch-item",
    "quantity": 20
}
```

### 2. Get Branches with Stock
```bash
GET /api/v1/purchase/orders/branches?item_id=uuid&quantity=20&exclude_branch_id=uuid
```

### 3. Purchase History
```bash
GET /api/v1/purchase/history
```

## البيانات الافتراضية

- **Email**: manager1@assab.com, manager2@assab.com, etc.
- **Password**: password123
- **Coordinates**: إحداثيات حقيقية للمدن السعودية
- **Prices**: أسعار واقعية بالريال السعودي


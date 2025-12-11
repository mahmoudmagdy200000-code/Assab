#!/bin/bash

# Script للتحقق من وجود الملفات المطلوبة على الخادم
# استخدم: bash check_files.sh

BASE_DIR="/home/u887387254/domains/ivory-snail-183262.hostingersite.com/public_html"

echo "=========================================="
echo "التحقق من الملفات المطلوبة"
echo "=========================================="
echo ""

# قائمة الملفات المطلوبة
files=(
    "Modules/Cashier/config/config.php"
    "Modules/Cashier/routes/api.php"
    "Modules/Cashier/routes/web.php"
    "Modules/BranchManagers/routes/api.php"
    "Modules/BranchManagers/routes/web.php"
    "Modules/Cashier/app/Providers/CashierServiceProvider.php"
    "Modules/Cashier/app/Providers/RouteServiceProvider.php"
    "Modules/BranchManagers/app/Providers/BranchManagersServiceProvider.php"
    "Modules/BranchManagers/app/Providers/RouteServiceProvider.php"
    "Modules/Purchase/app/Providers/RouteServiceProvider.php"
    "Modules/Aggregator/app/Providers/RouteServiceProvider.php"
    "Modules/BrandOwner/app/Providers/RouteServiceProvider.php"
    "Modules/Admin/app/Providers/RouteServiceProvider.php"
    "Modules/Branch/app/Providers/RouteServiceProvider.php"
)

# عداد الملفات الموجودة والمفقودة
found=0
missing=0

echo "الملفات المطلوبة:"
echo "-------------------"

for file in "${files[@]}"; do
    full_path="$BASE_DIR/$file"
    if [ -f "$full_path" ]; then
        echo "✅ $file"
        ((found++))
    else
        echo "❌ $file - مفقود!"
        ((missing++))
    fi
done

echo ""
echo "=========================================="
echo "النتيجة:"
echo "  ✅ موجود: $found"
echo "  ❌ مفقود: $missing"
echo "=========================================="

if [ $missing -eq 0 ]; then
    echo ""
    echo "🎉 جميع الملفات موجودة! يمكنك تشغيل composer install الآن."
    exit 0
else
    echo ""
    echo "⚠️  يوجد ملفات مفقودة. يرجى رفعها قبل تشغيل composer install."
    exit 1
fi

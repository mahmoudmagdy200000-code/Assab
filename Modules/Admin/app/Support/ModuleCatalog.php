<?php

namespace Modules\Admin\Support;

/**
 * SRS §2.3 — «الموديولات التسعة». The one list of dashboard modules, their
 * Arabic labels and icons. Lookups, the accountant module grid and the module
 * aggregation grid all read from here so no screen invents a tenth module or a
 * different label.
 */
final class ModuleCatalog
{
    public const MODULES = [
        'sales' => ['labelAr' => 'المبيعات', 'labelEn' => 'Sales', 'icon' => '💰'],
        'expenses' => ['labelAr' => 'المصروفات', 'labelEn' => 'Expenses', 'icon' => '🧾'],
        'purchases' => ['labelAr' => 'المشتريات', 'labelEn' => 'Purchases', 'icon' => '🛒'],
        'inventory' => ['labelAr' => 'المخزون', 'labelEn' => 'Inventory', 'icon' => '📦'],
        'waste' => ['labelAr' => 'الهدر', 'labelEn' => 'Waste', 'icon' => '🗑️'],
        'assets' => ['labelAr' => 'الأصول', 'labelEn' => 'Assets', 'icon' => '🏷️'],
        'shifts' => ['labelAr' => 'الورديات', 'labelEn' => 'Shifts', 'icon' => '🕐'],
        'employees' => ['labelAr' => 'الموظفين', 'labelEn' => 'Employees', 'icon' => '👥'],
        'cash' => ['labelAr' => 'النقدية', 'labelEn' => 'Cash', 'icon' => '💵'],
    ];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::MODULES);
    }

    public static function labelAr(string $key): string
    {
        return self::MODULES[$key]['labelAr'] ?? $key;
    }

    /** @return array<int, array{key:string, labelAr:string, labelEn:string, icon:string}> */
    public static function catalog(): array
    {
        return array_map(
            fn ($key, $meta) => ['key' => $key] + $meta,
            array_keys(self::MODULES),
            array_values(self::MODULES),
        );
    }
}

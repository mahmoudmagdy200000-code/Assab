<?php

namespace Modules\Admin\Support;

/**
 * The one Arabic label per dashboard role key.
 *
 * Lived as a private const inside UserController, so every other presenter that
 * needed to render a role either shipped the raw key or re-invented the map —
 * which is why the «يرفع تقريره إلى» picker had nothing readable to show next to
 * a name (reported 2026-08-03).
 */
final class RoleLabels
{
    public const LABELS = [
        'accountant' => 'محاسب',
        'head' => 'رئيس حسابات',
        'branch' => 'مدير فرع',
        'procurement' => 'مدير مشتريات',
        'supplier' => 'مورد',
        'admin' => 'أدمن',
        'brand-owner' => 'مالك العلامة التجارية',
    ];

    public static function labelAr(?string $roleKey): ?string
    {
        return $roleKey === null ? null : (self::LABELS[$roleKey] ?? $roleKey);
    }
}

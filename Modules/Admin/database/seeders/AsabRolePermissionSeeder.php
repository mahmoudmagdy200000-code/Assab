<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Admin\Models\AsabRole;
use Modules\Admin\Models\PermissionMatrixEntry;

class AsabRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['key' => 'admin', 'name_ar' => 'أمين النظام', 'name_en' => 'Admin'],
            ['key' => 'company-admin', 'name_ar' => 'أدمن الشركة', 'name_en' => 'Company Admin'],
            ['key' => 'head', 'name_ar' => 'رئيس الحسابات', 'name_en' => 'Head Accountant'],
            ['key' => 'accountant', 'name_ar' => 'المحاسب', 'name_en' => 'Accountant'],
            ['key' => 'branch', 'name_ar' => 'مدير الفرع', 'name_en' => 'Branch Manager'],
            ['key' => 'procurement', 'name_ar' => 'مدير المشتريات', 'name_en' => 'Procurement Manager'],
            ['key' => 'supplier', 'name_ar' => 'المورد', 'name_en' => 'Supplier'],
        ];
        foreach ($roles as $r) {
            AsabRole::updateOrCreate(['key' => $r['key']], $r);
        }

        // Default platform permission matrix (company_id = null). permission ∈ view|submit|review|approve|final|none
        $modules = ['المبيعات', 'المصروفات', 'المشتريات', 'المخزون', 'الهدر', 'الأصول', 'الورديات', 'الموظفين', 'النقدية', 'تصدير ERP', 'إدارة المستخدمين', 'إدارة الاشتراكات', 'الصلاحيات'];

        $matrix = [
            'admin' => 'final',       // admin manages everything
            'company-admin' => 'view', // sees own company; NO operation approval power
            'head' => 'final',        // final approver
            'accountant' => 'approve',
            'branch' => 'submit',
            'procurement' => 'review',
            'supplier' => 'view',
        ];

        foreach ($matrix as $roleKey => $default) {
            foreach ($modules as $module) {
                $perm = $default;
                // Management modules: platform admin everywhere; company-admin over users/subscriptions only.
                if (in_array($module, ['إدارة المستخدمين', 'إدارة الاشتراكات'], true)) {
                    $perm = in_array($roleKey, ['admin', 'company-admin'], true) ? 'final' : 'none';
                }
                if ($module === 'الصلاحيات') {
                    $perm = $roleKey === 'admin' ? 'final' : 'none';
                }
                if ($module === 'تصدير ERP') {
                    $perm = in_array($roleKey, ['admin', 'head'], true) ? 'final' : 'none';
                }
                PermissionMatrixEntry::updateOrCreate(
                    ['company_id' => null, 'role_key' => $roleKey, 'module' => $module],
                    ['permission' => $perm],
                );
            }
        }
    }
}

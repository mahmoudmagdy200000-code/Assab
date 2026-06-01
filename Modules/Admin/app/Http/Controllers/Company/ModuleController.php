<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\CompanyModule;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\RealtimeBroadcaster;

/**
 * Per-company module toggle (COMPANY_DASHBOARD_API_SPEC.md §5.1.5).
 */
class ModuleController extends AsabController
{
    private const META = [
        'sales' => ['ar' => 'المبيعات', 'en' => 'Sales', 'icon' => '💰', 'descAr' => 'تسجيل ومراجعة المبيعات اليومية'],
        'expenses' => ['ar' => 'المصروفات', 'en' => 'Expenses', 'icon' => '🧾', 'descAr' => 'فواتير ومصروفات التشغيل'],
        'purchases' => ['ar' => 'المشتريات', 'en' => 'Purchases', 'icon' => '🛒', 'descAr' => 'أوامر الشراء والموردون'],
        'inventory' => ['ar' => 'المخزون', 'en' => 'Inventory', 'icon' => '📦', 'descAr' => 'الجرد والمخزون'],
        'assets' => ['ar' => 'الأصول', 'en' => 'Assets', 'icon' => '🏗️', 'descAr' => 'الأصول الثابتة والإهلاك'],
        'shifts' => ['ar' => 'الورديات', 'en' => 'Shifts', 'icon' => '🕐', 'descAr' => 'إدارة ورديات الموظفين'],
        'waste' => ['ar' => 'الهدر', 'en' => 'Waste', 'icon' => '🗑️', 'descAr' => 'الهدر والتالف'],
        'emp' => ['ar' => 'الموظفين', 'en' => 'Employees', 'icon' => '👥', 'descAr' => 'بيانات الموظفين وحساباتهم'],
        'cash' => ['ar' => 'النقدية', 'en' => 'Cash', 'icon' => '💵', 'descAr' => 'العهد النقدية والتسويات'],
    ];

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $mods = CompanyModule::where('company_id', $request->user()->company_id)->get()->keyBy('module_key');

            $data = [];
            foreach (self::META as $key => $m) {
                $row = $mods->get($key);
                $data[] = [
                    'id' => $row?->id, 'moduleKey' => $key, 'nameAr' => $m['ar'], 'nameEn' => $m['en'],
                    'descAr' => $m['descAr'], 'descEn' => $m['en'], 'icon' => $m['icon'],
                    'isActive' => (bool) ($row?->is_active), 'isInPlan' => (bool) ($row?->is_in_plan ?? false),
                    'toggledAt' => optional($row?->toggled_at)->toIso8601String(), 'toggledBy' => null,
                ];
            }

            $active = count(array_filter($data, fn ($d) => $d['isActive']));
            $available = count(array_filter($data, fn ($d) => ! $d['isActive'] && $d['isInPlan']));
            $upgrade = count(array_filter($data, fn ($d) => ! $d['isInPlan']));

            return $this->listResponse($data, ['activeCount' => $active, 'availableCount' => $available, 'upgradeRequiredCount' => $upgrade]);
        });
    }

    public function toggle(Request $request, RealtimeBroadcaster $rt, string $moduleKey): JsonResponse
    {
        return $this->run(function () use ($request, $rt, $moduleKey) {
            $data = $request->validate(['isActive' => 'required|boolean']);
            $mod = CompanyModule::where('company_id', $request->user()->company_id)->where('module_key', $moduleKey)->firstOrFail();

            if ($data['isActive'] && ! $mod->is_in_plan) {
                throw new AsabException('UPGRADE_REQUIRED', 'Module requires a plan upgrade', 'يحتاج ترقية الخطة', 403, ['moduleKey' => $moduleKey]);
            }
            if (! $data['isActive']) {
                $pending = Operation::where('company_id', $request->user()->company_id)->where('module_key', $moduleKey)
                    ->whereIn('status', [Operation::STATUS_PENDING, Operation::STATUS_APPROVED])->count();
                if ($pending > 0) {
                    throw new AsabException('MODULE_HAS_PENDING_OPS', 'Module has open operations', 'يوجد عمليات مفتوحة لهذا الموديول', 409, ['pending' => $pending]);
                }
            }

            $mod->update(['is_active' => $data['isActive'], 'toggled_by_id' => $request->user()->id, 'toggled_at' => now()]);
            $rt->moduleChanged($request->user()->company_id, $moduleKey, $data['isActive']);

            return $this->ok(['id' => $mod->id, 'moduleKey' => $moduleKey, 'isActive' => (bool) $mod->is_active]);
        });
    }
}

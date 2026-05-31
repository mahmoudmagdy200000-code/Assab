<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Operation;

/**
 * Pipeline overview + module aggregation grid (BACKEND_API_SPEC.md §7.9 / §7.10).
 */
class PipelineController extends AsabController
{
    private const MODULES = [
        'sales' => 'المبيعات', 'expenses' => 'المصروفات', 'purchases' => 'المشتريات',
        'inventory' => 'المخزون', 'waste' => 'الهدر', 'shifts' => 'الورديات',
        'employees' => 'الموظفين', 'cash' => 'النقدية',
    ];

    public function overview(): JsonResponse
    {
        return $this->run(function () {
            $byStatus = Operation::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

            $stages = [
                ['stageId' => 'submit', 'label' => 'رُفع من الفرع', 'count' => (int) ($byStatus['pending'] ?? 0)],
                ['stageId' => 'review', 'label' => 'قيد المراجعة', 'count' => (int) ($byStatus['pending'] ?? 0)],
                ['stageId' => 'approved', 'label' => 'موافق عليه', 'count' => (int) ($byStatus['approved'] ?? 0)],
                ['stageId' => 'final', 'label' => 'معتمد نهائياً', 'count' => (int) ($byStatus['final-approved'] ?? 0)],
                ['stageId' => 'erp', 'label' => 'مُرحَّل لـ ERP', 'count' => Operation::where('erp_posted', true)->count()],
            ];

            return $this->ok([
                'stages' => $stages,
                'rejectedCount' => (int) ($byStatus['rejected'] ?? 0),
                'totalOperations' => Operation::count(),
            ]);
        });
    }

    public function aggregation(): JsonResponse
    {
        return $this->run(function () {
            $rows = [];
            foreach (self::MODULES as $key => $label) {
                $base = Operation::where('module_key', $key);
                $counts = [
                    'pending' => (clone $base)->where('status', 'pending')->count(),
                    'approved' => (clone $base)->where('status', 'approved')->count(),
                    'final' => (clone $base)->where('status', 'final-approved')->count(),
                    'erp' => (clone $base)->where('erp_posted', true)->count(),
                ];
                $total = array_sum($counts);
                $rows[] = [
                    'moduleKey' => $key,
                    'label' => $label,
                    'state' => $this->state($counts, $total),
                    'counts' => $counts,
                    'totalAmount' => (int) (clone $base)->sum('amount'),
                ];
            }

            return $this->listResponse($rows);
        });
    }

    private function state(array $counts, int $total): string
    {
        if ($total === 0) {
            return 'empty';
        }
        if ($counts['erp'] === $total) {
            return 'exported';
        }
        if ($counts['final'] > 0) {
            return 'ready_erp';
        }
        if ($counts['approved'] > 0) {
            return 'ready_consolidation';
        }

        return 'incomplete';
    }
}

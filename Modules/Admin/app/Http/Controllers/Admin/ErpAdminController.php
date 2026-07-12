<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\ErpBatch;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\ErpBatchService;

/**
 * SRS §14.3 ERP-2 — the platform admin (أمين النظام) ERP export screen:
 * connection banner + KPIs, a cross-company filterable batch log, and a
 * select/all-ready bulk export. Admin bypasses the tenant scope, so this sees
 * every company's batches (an optional companyId filter narrows it).
 */
class ErpAdminController extends AsabController
{
    public function __construct(private readonly ErpBatchService $erp) {}

    /** GET /admin/erp/summary — connection banner + KPI block. */
    public function summary(): JsonResponse
    {
        return $this->run(function () {
            $lastExported = ErpBatch::where('status', 'exported')->orderByDesc('completed_at')->first();

            return $this->ok([
                'connection' => [
                    'ok' => true,
                    'label' => 'متصل ونشط',
                    'lastExportAt' => optional($lastExported?->completed_at)->toIso8601String(),
                    'lastExportCount' => (int) ($lastExported?->operation_count ?? 0),
                ],
                'kpis' => [
                    'ready' => ErpBatch::where('status', 'ready')->count(),
                    'exportedToday' => ErpBatch::where('status', 'exported')->whereDate('completed_at', today())->count(),
                    // awaiting the head's final approval → ready for a batch once approved.
                    'awaitingHead' => Operation::where('status', Operation::STATUS_APPROVED)->count(),
                    'failed' => ErpBatch::where('status', 'failed')->count(),
                ],
            ]);
        });
    }

    /** GET /admin/erp/batches — cross-company log with module/status/period filters. */
    public function batches(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = ErpBatch::query();
            if ($companyId = $request->query('companyId')) {
                $q->where('company_id', $companyId);
            }
            if ($module = $request->query('moduleKey')) {
                $q->where('module_key', $module);
            }
            if ($status = $request->query('status')) {
                $q->where('status', $status);
            }
            if ($from = $request->query('dateFrom')) {
                $q->whereDate('batch_date', '>=', $from);
            }
            if ($to = $request->query('dateTo')) {
                $q->whereDate('batch_date', '<=', $to);
            }
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $p = $q->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map(fn (ErpBatch $b) => $this->erp->present($b) + ['companyId' => $b->company_id], $p->items()));
        });
    }

    /** POST /admin/erp/export {batchIds?[], allReady?} — export selected / all ready batches. */
    public function export(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'batchIds' => 'sometimes|array',
                'batchIds.*' => 'string',
                'allReady' => 'sometimes|boolean',
            ]);

            $q = ErpBatch::whereIn('status', ['ready', 'failed']);
            if (! empty($data['allReady'])) {
                $q->where('status', 'ready');
            } elseif (! empty($data['batchIds'])) {
                $q->where(fn ($w) => $w->whereIn('id', $data['batchIds'])->orWhereIn('batch_id', $data['batchIds']));
            } else {
                return $this->fail('VALIDATION_ERROR', 'Provide batchIds or allReady', 'حدد الدفعات أو اختر تصدير الكل', ['batchIds' => ['required unless allReady']], 422);
            }

            $exported = [];
            $failed = [];
            foreach ($q->get() as $batch) {
                $result = $this->erp->exportBatch($batch, $request->user());
                if ($result->status === 'exported') {
                    $exported[] = $result->batch_id;
                } else {
                    $failed[] = $result->batch_id;
                }
            }

            return $this->ok(['exported' => $exported, 'failed' => $failed, 'count' => count($exported)]);
        });
    }
}

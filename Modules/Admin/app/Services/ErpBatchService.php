<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\ErpBatch;
use Modules\Admin\Models\Operation;

/**
 * ERP export batching (BACKEND_API_SPEC.md §5 / §6.2.8). Posts final-approved,
 * not-yet-posted operations into a batch and stamps erp_posted on each.
 */
class ErpBatchService
{
    public function __construct(private readonly RealtimeBroadcaster $rt) {}

    /**
     * @param  array{operationIds?: string[], filters?: array}  $input
     */
    public function create(array $input, AsabUser $actor): ErpBatch
    {
        $ops = $this->eligible($input)->get();

        if ($ops->isEmpty()) {
            throw new AsabException('NO_ELIGIBLE_OPS', 'No final-approved operations to export', 'لا توجد عمليات معتمدة للتصدير', 422);
        }

        $batch = DB::transaction(function () use ($ops, $actor, $input) {
            $batchPublicId = 'ERP-BATCH-'.now()->format('Ymd').'-'.str_pad((string) (ErpBatch::whereDate('created_at', today())->count() + 1), 3, '0', STR_PAD_LEFT);

            $batch = ErpBatch::create([
                'batch_id' => $batchPublicId,
                'company_id' => $actor->company_id ?? $ops->first()->company_id,
                'initiated_by_id' => $actor->id,
                'operation_count' => $ops->count(),
                'total_amount' => $ops->sum('amount'),
                'status' => 'success', // synchronous mock post; real connector would be async/queued
                'filters' => $input['filters'] ?? null,
                'branch_count' => $ops->pluck('branch_id')->unique()->count(),
                'erp_response' => ['mock' => true, 'postedAt' => now()->toIso8601String()],
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            foreach ($ops as $op) {
                $op->update([
                    'erp_posted' => true,
                    'erp_batch_id' => $batch->batch_id,
                    'erp_posted_at' => now(),
                ]);
                DB::table('asab_erp_batch_operations')->insert([
                    'batch_id' => $batch->id,
                    'operation_id' => $op->id,
                ]);
                ApprovalStep::create([
                    'operation_id' => $op->id,
                    'stage_id' => 'erp',
                    'action' => 'مُرحَّل لـ ERP — دفعة '.$batch->batch_id,
                    'actor_user_id' => $actor->id,
                    'actor_label' => $actor->name,
                    'occurred_at' => now(),
                ]);
            }

            return $batch;
        });

        $this->rt->erpBatchCompleted($batch);

        return $batch;
    }

    public function status(string $batchPublicId): array
    {
        $batch = ErpBatch::where('batch_id', $batchPublicId)->orWhere('id', $batchPublicId)->first();
        if (! $batch) {
            throw new AsabException('NOT_FOUND', 'ERP batch not found', 'دفعة التصدير غير موجودة', 404);
        }

        return [
            'batchId' => $batch->batch_id,
            'status' => $batch->status,
            'completedAt' => optional($batch->completed_at)->toIso8601String(),
            'operationCount' => $batch->operation_count,
            'totalAmount' => $batch->total_amount,
            'erpResponse' => $batch->erp_response,
        ];
    }

    public function preflight(): array
    {
        $eligible = Operation::where('status', Operation::STATUS_FINAL)->where('erp_posted', false)->count();
        $unresolvedDiffs = Operation::where('match', 'diff')->whereIn('status', ['pending', 'approved'])->count();

        // labelAr/labelEn are the FE-contract fields (B-H4); `label` kept as the
        // Arabic back-compat alias.
        $checks = [
            [
                'ok' => $eligible > 0,
                'labelAr' => "عمليات جاهزة للتصدير: {$eligible}",
                'labelEn' => "Operations ready to export: {$eligible}",
                'label' => "عمليات جاهزة للتصدير: {$eligible}",
                'severity' => $eligible > 0 ? 'info' : 'warning',
            ],
            [
                'ok' => $unresolvedDiffs === 0,
                'labelAr' => "فروقات غير محلولة: {$unresolvedDiffs}",
                'labelEn' => "Unresolved discrepancies: {$unresolvedDiffs}",
                'label' => "فروقات غير محلولة: {$unresolvedDiffs}",
                'severity' => $unresolvedDiffs === 0 ? 'info' : 'warning',
            ],
        ];

        return [
            'checks' => $checks,
            'canProceed' => $eligible > 0,
            'warningCount' => count(array_filter($checks, fn ($c) => ! $c['ok'])),
        ];
    }

    public function eligible(array $input)
    {
        $q = Operation::where('status', Operation::STATUS_FINAL)->where('erp_posted', false);

        if (! empty($input['operationIds'])) {
            $q->where(function ($w) use ($input) {
                $w->whereIn('id', $input['operationIds'])->orWhereIn('public_id', $input['operationIds']);
            });
        }
        $filters = $input['filters'] ?? [];
        if (! empty($filters['moduleKey']) && $filters['moduleKey'] !== 'all') {
            $q->where('module_key', $filters['moduleKey']);
        }
        if (! empty($filters['branchId'])) {
            $q->where('branch_id', $filters['branchId']);
        }
        if (! empty($filters['dateFrom'])) {
            $q->where('operation_date', '>=', $filters['dateFrom']);
        }
        if (! empty($filters['dateTo'])) {
            $q->where('operation_date', '<=', $filters['dateTo']);
        }

        return $q;
    }
}

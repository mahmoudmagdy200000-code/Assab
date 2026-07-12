<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\ErpBatch;
use Modules\Admin\Models\Operation;

/**
 * ERP export batching (SRS §14.3 ERP-1..3). A batch groups the final-approved
 * operations of ONE (day × module) and moves through:
 *
 *   ready ──export──▶ exported          (ops stamped erp_posted, م5 step written)
 *      │
 *      └──export fails──▶ failed ──retry──▶ exported
 *
 * A ready batch is seeded automatically when an operation is final-approved
 * (SyncErpReadyBatch listener), so the admin/head export screens can list what
 * is waiting before anyone posts. Exporting is a mockable connector call so
 * tests can force the failure path.
 */
class ErpBatchService
{
    public function __construct(private readonly RealtimeBroadcaster $rt) {}

    // ── Ready batches (seeded on final-approve) ─────────────────────────────────

    /**
     * Upsert the ready (day × module) batch for a freshly final-approved op and
     * attach it. Does NOT stamp erp_posted — the op is ready, not yet exported.
     */
    public function addToReadyBatch(Operation $op, AsabUser $actor): ErpBatch
    {
        if ($op->erp_posted) {
            return $this->findBatchForOp($op); // already exported
        }
        $companyId = $op->company_id;
        $day = $this->dayOf($op);
        $module = $op->module_key;

        return DB::transaction(function () use ($op, $actor, $companyId, $day, $module) {
            // Reuse the group's still-open batch (ready OR failed) rather than
            // creating a parallel one — otherwise the same op could be split
            // across two batches for one (company × module × day).
            $batch = $this->openBatch($companyId, $module, $day)
                ?? $this->createReadyBatch($companyId, $module, $day, $actor);
            $this->attach($batch, $op);
            $this->recount($batch);

            return $batch->fresh();
        });
    }

    // ── Export (the head/admin «ترحيل») ─────────────────────────────────────────

    /**
     * Export the eligible (final-approved ∧ not-posted) operations matching the
     * input, split into one batch per (day × module). Each group is posted via
     * the connector; on success its ops are stamped erp_posted + م5 step and the
     * batch flips to `exported`; a connector failure marks the batch `failed`.
     *
     * @param  array{operationIds?: string[], filters?: array}  $input
     * @return Collection<int, ErpBatch>
     */
    public function export(array $input, AsabUser $actor): Collection
    {
        $ops = $this->eligible($input)->get();
        if ($ops->isEmpty()) {
            throw new AsabException('NO_ELIGIBLE_OPS', 'No final-approved operations to export', 'لا توجد عمليات معتمدة للتصدير', 422);
        }

        $groups = $ops->groupBy(fn (Operation $o) => $this->dayOf($o).'|'.$o->module_key);
        $batches = collect();

        DB::transaction(function () use ($groups, $actor, &$batches) {
            foreach ($groups as $groupOps) {
                $first = $groupOps->first();
                $batch = $this->openBatch($first->company_id, $first->module_key, $this->dayOf($first))
                    ?? $this->addToReadyBatch($first, $actor);

                foreach ($groupOps as $op) {
                    $this->attach($batch, $op);
                }
                $this->recount($batch);
                // Post the batch's FULL membership (day × module is the ERP unit) —
                // posting only the filtered subset would flip the batch to
                // `exported` while stranding its other members unposted.
                $this->postBatch($batch, $this->batchOps($batch), $actor);
                $batches->push($batch->fresh());
            }
        });

        foreach ($batches as $b) {
            if ($b->status === 'exported') {
                $this->rt->erpBatchCompleted($b);
            }
        }

        return $batches;
    }

    /**
     * ERP-2 admin — export one existing ready (or failed) batch: post it, stamp
     * its ops on success. 409 if the batch is already exported.
     */
    public function exportBatch(ErpBatch $batch, AsabUser $actor): ErpBatch
    {
        if (! in_array($batch->status, ['ready', 'failed'], true)) {
            throw new AsabException('BATCH_NOT_EXPORTABLE', 'Only a ready or failed batch can be exported', 'لا يمكن تصدير إلا دفعة جاهزة أو فاشلة', 409, ['currentStatus' => $batch->status]);
        }
        $ops = $this->batchOps($batch);
        DB::transaction(fn () => $this->postBatch($batch, $ops, $actor));

        $fresh = $batch->fresh();
        if ($fresh->status === 'exported') {
            $this->rt->erpBatchCompleted($fresh);
        }

        return $fresh;
    }

    /**
     * T10.6 — retry a failed batch: re-post via the connector, stamping any of
     * its still-unposted ops on success. 409 if the batch is not `failed`.
     */
    public function retry(string $batchId, AsabUser $actor): ErpBatch
    {
        $batch = $this->resolve($batchId);
        if ($batch->status !== 'failed') {
            throw new AsabException('BATCH_NOT_FAILED', 'Only a failed batch can be retried', 'لا يمكن إعادة المحاولة إلا لدفعة فاشلة', 409, ['currentStatus' => $batch->status]);
        }

        $ops = $this->batchOps($batch);
        DB::transaction(function () use ($batch, $ops, $actor) {
            $this->postBatch($batch, $ops, $actor);
        });

        $fresh = $batch->fresh();
        if ($fresh->status === 'exported') {
            $this->rt->erpBatchCompleted($fresh);
        }

        return $fresh;
    }

    /**
     * Post one batch to the ERP connector. On success stamp every still-unposted
     * op (erp_posted + م5 step) and flip the batch `exported`; on a connector
     * failure mark it `failed` and touch nothing on the operations.
     *
     * @param  Collection<int, Operation>  $ops
     */
    private function postBatch(ErpBatch $batch, Collection $ops, AsabUser $actor): void
    {
        $batch->update(['started_at' => $batch->started_at ?? now()]);
        $response = $this->postToConnector($batch);

        if (empty($response['ok'])) {
            $batch->update(['status' => 'failed', 'erp_response' => $response, 'completed_at' => null]);

            return;
        }

        foreach ($ops as $op) {
            if ($op->erp_posted) {
                continue;
            }
            $op->update(['erp_posted' => true, 'erp_batch_id' => $batch->batch_id, 'erp_posted_at' => now()]);
            ApprovalStep::create([
                'operation_id' => $op->id, 'stage_id' => 'erp',
                'action' => 'مُرحَّل لـ ERP — دفعة '.$batch->batch_id,
                'actor_user_id' => $actor->id, 'actor_label' => $actor->name, 'occurred_at' => now(),
            ]);
        }
        $batch->update(['status' => 'exported', 'erp_response' => $response, 'completed_at' => now()]);
    }

    /**
     * The (mockable) ERP connector post. Real integrations would be async/queued;
     * this synchronous mock returns ok unless a forced-failure flag is set (tests
     * flip config('asab.erp.force_fail') to exercise the failed→retry path).
     *
     * @return array{ok:bool, ...}
     */
    protected function postToConnector(ErpBatch $batch): array
    {
        if (config('asab.erp.force_fail')) {
            return ['ok' => false, 'error' => 'ERP connector unavailable', 'failedAt' => now()->toIso8601String()];
        }

        return ['ok' => true, 'mock' => true, 'postedAt' => now()->toIso8601String()];
    }

    // ── Reads ───────────────────────────────────────────────────────────────────

    public function status(string $batchId): array
    {
        return $this->present($this->resolve($batchId));
    }

    /** @return array<string,mixed> */
    public function present(ErpBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'batchId' => $batch->batch_id,
            'moduleKey' => $batch->module_key,
            'batchDate' => optional($batch->batch_date)->toDateString(),
            'status' => $batch->status,
            'statusLabelAr' => ErpBatch::statusLabel($batch->status),
            'operationCount' => (int) $batch->operation_count,
            'totalAmount' => (int) $batch->total_amount,
            'totalAmountHalalas' => (int) $batch->total_amount,
            'branchCount' => (int) $batch->branch_count,
            'approvedById' => $batch->approved_by_id,
            'initiatedById' => $batch->initiated_by_id,
            'readyAt' => optional($batch->ready_at)->toIso8601String(),
            'startedAt' => optional($batch->started_at)->toIso8601String(),
            'completedAt' => optional($batch->completed_at)->toIso8601String(),
            'createdAt' => optional($batch->created_at)->toIso8601String(),
            'erpResponse' => $batch->erp_response,
        ];
    }

    public function preflight(): array
    {
        $eligible = Operation::where('status', Operation::STATUS_FINAL)->where('erp_posted', false)->count();
        $unresolvedDiffs = Operation::where('match', 'diff')->whereIn('status', ['pending', 'approved'])->count();

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

    /** Final-approved ∧ not-yet-posted operations, filtered. Returns a builder. */
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

    // ── helpers ─────────────────────────────────────────────────────────────────

    /** Resolve a batch by public batch_id or uuid (tenant-scoped via the model). */
    private function resolve(string $batchId): ErpBatch
    {
        // Group the id/public-id predicate so it stays ANDed under the tenant
        // global scope (a bare orWhere would escape the company_id filter).
        $batch = ErpBatch::where(fn ($q) => $q->where('batch_id', $batchId)->orWhere('id', $batchId))->first();
        if (! $batch) {
            throw new AsabException('NOT_FOUND', 'ERP batch not found', 'دفعة التصدير غير موجودة', 404);
        }

        return $batch;
    }

    private function findBatchForOp(Operation $op): ErpBatch
    {
        return $this->resolve($op->erp_batch_id ?? '__none__');
    }

    /** @return Collection<int, Operation> */
    private function batchOps(ErpBatch $batch): Collection
    {
        $opIds = DB::table('asab_erp_batch_operations')->where('batch_id', $batch->id)->pluck('operation_id');

        return Operation::withoutGlobalScopes()->whereIn('id', $opIds)->get();
    }

    private function attach(ErpBatch $batch, Operation $op): void
    {
        $exists = DB::table('asab_erp_batch_operations')
            ->where('batch_id', $batch->id)->where('operation_id', $op->id)->exists();
        if (! $exists) {
            DB::table('asab_erp_batch_operations')->insert(['batch_id' => $batch->id, 'operation_id' => $op->id]);
        }
    }

    private function recount(ErpBatch $batch): void
    {
        $ops = $this->batchOps($batch);
        $batch->update([
            'operation_count' => $ops->count(),
            'total_amount' => (int) $ops->sum('amount'),
            'branch_count' => $ops->pluck('branch_id')->filter()->unique()->count(),
        ]);
    }

    private function dayOf(Operation $op): string
    {
        return ($op->operation_date ?? $op->created_at ?? now())->toDateString();
    }

    /** The still-open (ready|failed) batch for a (company × module × day), if any. */
    private function openBatch(string $companyId, ?string $module, string $day): ?ErpBatch
    {
        return ErpBatch::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('module_key', $module)
            ->whereDate('batch_date', $day)->whereIn('status', ['ready', 'failed'])->first();
    }

    /**
     * Create a ready batch, retrying on the (globally-unique) batch_id collision
     * that a concurrent same-day create can cause. The daily sequence is global
     * so batch_ids stay unique+sequential; the retry recomputes it under contention.
     */
    private function createReadyBatch(string $companyId, ?string $module, string $day, AsabUser $actor): ErpBatch
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return ErpBatch::create([
                    'batch_id' => $this->nextBatchId($day),
                    'company_id' => $companyId,
                    'module_key' => $module,
                    'batch_date' => $day,
                    'initiated_by_id' => $actor->id,
                    'approved_by_id' => $actor->id,
                    'status' => 'ready',
                    'ready_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($attempt >= 4) {
                    throw $e;
                }
            }
        }
    }

    /** EXP-YYYY-MM-DD-nnn, nnn a global daily sequence (keeps batch_id unique). */
    private function nextBatchId(string $day): string
    {
        $seq = ErpBatch::withoutGlobalScopes()->whereDate('batch_date', $day)->count() + 1;

        return 'EXP-'.$day.'-'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}

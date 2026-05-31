<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\ErpBatch;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\ErpBatchService;

/**
 * ERP batch status + downloads (BACKEND_API_SPEC.md §5 / §7.4).
 */
class ErpController extends AsabController
{
    public function __construct(private readonly ErpBatchService $erp) {}

    public function status(string $batchId): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->erp->status($batchId)));
    }

    public function downloadJson(string $batchId): JsonResponse
    {
        return $this->run(fn () => $this->ok([
            'batchId' => $batchId,
            'operations' => $this->batchOperations($batchId),
        ]));
    }

    public function downloadCsv(string $batchId): Response
    {
        $rows = $this->batchOperations($batchId);
        $csv = "publicId,moduleKey,branchId,amount,status,operationDate\n";
        foreach ($rows as $r) {
            $csv .= implode(',', [$r['publicId'], $r['moduleKey'], $r['branchId'], $r['amount'], $r['status'], $r['operationDate']])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$batchId}.csv\"",
        ]);
    }

    public function downloadXlsx(string $batchId): Response
    {
        return $this->downloadCsv($batchId);
    }

    private function batchOperations(string $batchId): array
    {
        $batch = ErpBatch::where('batch_id', $batchId)->orWhere('id', $batchId)->firstOrFail();
        $opIds = DB::table('asab_erp_batch_operations')->where('batch_id', $batch->id)->pluck('operation_id');

        return Operation::whereIn('id', $opIds)->get()->map(fn ($o) => [
            'publicId' => $o->public_id,
            'moduleKey' => $o->module_key,
            'branchId' => $o->branch_id,
            'amount' => $o->amount,
            'status' => $o->status,
            'operationDate' => optional($o->operation_date)->toIso8601String(),
        ])->all();
    }
}

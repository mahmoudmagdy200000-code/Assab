<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\ErpBatch;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\ErpBatchService;
use Modules\Admin\Services\ExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ERP batch status + downloads + retry (SRS §14.3). Restricted to head+admin
 * (routes); batch lookups are tenant-scoped via the ErpBatch global scope.
 */
class ErpController extends AsabController
{
    public function __construct(
        private readonly ErpBatchService $erp,
        private readonly ExportService $exports,
    ) {}

    public function status(string $batchId): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->erp->status($batchId)));
    }

    /** T10.6 — retry a failed batch (head/admin). */
    public function retry(Request $request, string $batchId): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->erp->present($this->erp->retry($batchId, $request->user()))));
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

    /** T10.8 — a real xlsx (was serving CSV bytes with an .xlsx name). */
    public function downloadXlsx(string $batchId): BinaryFileResponse
    {
        $rows = $this->batchOperations($batchId);
        $headings = ['رقم العملية', 'الموديول', 'الفرع', 'المبلغ (هللة)', 'الحالة', 'التاريخ'];
        $sheet = array_map(fn ($r) => [
            $r['publicId'], $r['moduleKey'], $r['branchId'], $r['amount'], $r['status'], $r['operationDate'],
        ], $rows);

        return $this->exports->make('xlsx', $batchId, $headings, $sheet);
    }

    /** Tenant-scoped batch → its operations. The grouped predicate keeps the
     *  id/public-id match ANDed under the ErpBatch company global scope. */
    private function batchOperations(string $batchId): array
    {
        $batch = ErpBatch::where(fn ($q) => $q->where('batch_id', $batchId)->orWhere('id', $batchId))->firstOrFail();
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

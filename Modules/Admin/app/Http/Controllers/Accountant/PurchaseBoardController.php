<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\ExportService;
use Modules\Admin\Services\PurchaseBoardService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ACC-3 «موديول المشتريات» — the grouped board («عرض حسب المورد» / «عرض حسب
 * الفرع»), its KPI header and its Excel export. Thin HTTP layer; the folding
 * lives in {@see PurchaseBoardService}.
 */
class PurchaseBoardController extends AsabController
{
    public function __construct(
        private readonly PurchaseBoardService $board,
        private readonly ExportService $exports,
    ) {}

    /** GET …/purchases — cards + invoices + lines for the purchases screen. */
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $filters = $this->filters($request);

            $result = $this->board->board(
                $this->tenantCompanyIdsFor($request->user()),
                $this->assignedBranchIds(),
                $filters,
            );

            return $this->listResponse($result['groups'], $result['meta'] + ['kpis' => $result['kpis']]);
        });
    }

    /** GET …/purchases/export — the same view, flattened to one sheet. */
    public function export(Request $request): BinaryFileResponse
    {
        $filters = $this->filters($request);
        // The sheet is the whole filtered view, not one page of cards.
        $filters['page'] = 1;
        $filters['pageSize'] = 100;

        $result = $this->board->board(
            $this->tenantCompanyIdsFor($request->user()),
            $this->assignedBranchIds(),
            $filters,
        );

        $format = $request->query('format') === 'csv' ? 'csv' : 'xlsx';

        return $this->exports->purchaseBoard($format, $result['groups'], $result['meta']['groupBy']);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $request->validate([
            'groupBy' => 'sometimes|in:supplier,branch',
            'status' => 'sometimes|nullable|string|max:80',
            'match' => 'sometimes|nullable|string|max:40',
            // A query string carries «true»/«false», which the `boolean` rule
            // rejects — the toggle would have 422'd on every click.
            'documented' => 'sometimes|nullable|in:1,0,true,false',
            'dateFrom' => 'sometimes|nullable|date',
            'dateTo' => 'sometimes|nullable|date',
            'page' => 'sometimes|integer|min:1',
            'pageSize' => 'sometimes|integer|min:1|max:100',
        ]);

        return [
            'groupBy' => $request->query('groupBy', 'supplier'),
            'brandId' => $request->query('brandId'),
            'branchId' => $request->query('branchId'),
            'supplierId' => $request->query('supplierId'),
            'status' => $request->query('status'),
            'match' => $request->query('match'),
            'documented' => $request->query('documented'),
            // `q` is canonical; `search` is the shared-list alias.
            'q' => $request->query('q', $request->query('search')),
            'dateFrom' => $request->query('dateFrom'),
            'dateTo' => $request->query('dateTo'),
            'page' => (int) $request->query('page', 1),
            'pageSize' => (int) $request->query('pageSize', 20),
        ];
    }

    /**
     * POST …/purchases/bulk-document — «موافقة جماعية» on the board: stamp
     * توثيق on every line of the given invoices in one call.
     */
    public function bulkDocument(Request $request, \Modules\Admin\Services\PurchasePresenterService $purchases): JsonResponse
    {
        return $this->run(function () use ($request, $purchases) {
            $data = $request->validate([
                'operationIds' => 'required|array|min:1|max:100',
                'operationIds.*' => 'required|string',
                'documented' => 'sometimes|boolean',
            ]);
            $documented = $data['documented'] ?? true;

            $stamp = $documented ? [
                'documentedAt' => now()->toIso8601String(),
                'documentedBy' => $request->user()->id,
                'documentedByName' => $request->user()->name,
            ] : null;

            // Zero-trust: out-of-scope ids are simply not found, never touched.
            // company_id is stated explicitly — Operation's tenant scope steps
            // aside for platform roles, so no reader may inherit one.
            $ops = $this->scopeToAssignedBranches(
                Operation::whereIn('company_id', $this->tenantCompanyIdsFor($request->user()))
                    ->where('module_key', 'purchases')
                    ->whereIn('id', $data['operationIds'])
            )->get();

            $updated = \Illuminate\Support\Facades\DB::transaction(function () use ($ops, $purchases, $stamp) {
                $count = 0;
                foreach ($ops as $op) {
                    $payload = $op->payload ?? [];
                    $lines = $purchases->lines($op);
                    foreach (array_keys($lines) as $i) {
                        $lines[$i]['documentation'] = $stamp;
                    }
                    $payload['purchaseItems'] = $lines;
                    $payload['documentation'] = $stamp;
                    $op->update(['payload' => $payload]);
                    $count++;
                }

                return $count;
            });

            return $this->ok([
                'documented' => $documented,
                'operations' => $updated,
                'skipped' => count($data['operationIds']) - $updated,
            ]);
        });
    }
}

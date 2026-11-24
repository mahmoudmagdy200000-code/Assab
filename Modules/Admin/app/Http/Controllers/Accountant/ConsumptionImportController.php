<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Services\ConsumptionImportService;
use Modules\Admin\Services\MonthlyWeightedCostService;
use Modules\Admin\Services\WasteKpiService;
use Modules\Admin\Support\AsabTenant;
use Modules\Branch\Models\Branch;

/**
 * 05-CONSUMPTION-IMPORT.md — External consumption `.xlsx` preview/confirm,
 * Waste Rate KPI references, and monthly weighted-average cost recalculation/approval.
 */
class ConsumptionImportController extends AsabController
{
    public function __construct(
        private readonly ConsumptionImportService $imports,
        private readonly WasteKpiService $wasteKpis,
        private readonly MonthlyWeightedCostService $monthlyCosts,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate([
                'file' => ['required', 'file', 'mimes:xlsx,csv,txt'],
                'sourceSystem' => ['nullable', 'string', 'max:50'],
            ]);

            $actor = $request->user();
            $companyId = (string) ($actor->company_id ?: AsabTenant::currentCompanyId());
            $file = $request->file('file');

            $batch = $this->imports->previewFile(
                $file->getRealPath(),
                $file->getClientOriginalName(),
                $companyId,
                $this->assignedBranchIds(),
                $actor,
                (string) ($request->input('sourceSystem') ?: 'external_pos'),
            );

            return $this->ok($batch->toArray());
        });
    }

    public function confirm(Request $request, string $batchId): JsonResponse
    {
        return $this->run(function () use ($request, $batchId) {
            $actor = $request->user();
            $companyId = (string) ($actor->company_id ?: AsabTenant::currentCompanyId());

            $batch = $this->imports->confirm($batchId, $companyId, $actor);

            return $this->ok($batch->toArray());
        });
    }

    public function wasteKpiReferences(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $actor = $request->user();
            $companyId = (string) ($actor->company_id ?: AsabTenant::currentCompanyId());
            $dateFrom = (string) ($request->query('dateFrom') ?: Carbon::now()->startOfMonth()->toDateString());
            $dateTo = (string) ($request->query('dateTo') ?: Carbon::now()->toDateString());

            $branchId = $request->query('branchId');
            if ($branchId) {
                $this->assertBranchAssigned((string) $branchId);
                $branchIds = [(string) $branchId];
            } else {
                $q = Branch::query()->where('asab_company_id', $companyId);
                if (($assigned = $this->assignedBranchIds()) !== null) {
                    $q->whereIn('id', $assigned);
                }
                $branchIds = $q->orderBy('name')->pluck('id')->all();
            }

            $branches = [];
            $availableCount = 0;
            $unavailableCount = 0;
            foreach ($branchIds as $bid) {
                $row = $this->wasteKpis->calculateForBranch($companyId, (string) $bid, $dateFrom, $dateTo);
                if (! empty($row['available'])) {
                    $availableCount++;
                } else {
                    $unavailableCount++;
                }
                $branches[] = $row;
            }

            return $this->ok([
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'availableCount' => $availableCount,
                'unavailableCount' => $unavailableCount,
                'branches' => $branches,
            ]);
        });
    }

    public function recalculateMonthlyCost(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'branchId' => ['required', 'string'],
                'itemId' => ['required', 'string'],
                'yearMonth' => ['required', 'regex:/^\d{4}-\d{2}$/'],
                'openingQty' => ['required', 'numeric', 'min:0'],
                'openingValueHalalas' => ['required', 'integer', 'min:0'],
                'purchasesQty' => ['nullable', 'numeric', 'min:0'],
                'purchasesValueHalalas' => ['nullable', 'integer', 'min:0'],
                'transfersInQty' => ['nullable', 'numeric', 'min:0'],
                'transfersInValueHalalas' => ['nullable', 'integer', 'min:0'],
                'status' => ['nullable', 'in:PROVISIONAL,APPROVED'],
            ]);

            $this->assertBranchAssigned($data['branchId']);
            $actor = $request->user();
            $companyId = (string) ($actor->company_id ?: AsabTenant::currentCompanyId());

            $cost = $this->monthlyCosts->recalculate(
                $companyId,
                $data['branchId'],
                $data['itemId'],
                $data['yearMonth'],
                (float) $data['openingQty'],
                (int) $data['openingValueHalalas'],
                (float) ($data['purchasesQty'] ?? 0),
                (int) ($data['purchasesValueHalalas'] ?? 0),
                (float) ($data['transfersInQty'] ?? 0),
                (int) ($data['transfersInValueHalalas'] ?? 0),
                (string) ($data['status'] ?? 'PROVISIONAL'),
                ($data['status'] ?? null) === 'APPROVED' ? (string) $actor->id : null,
            );

            return $this->ok($cost->toArray());
        });
    }

    public function approveMonthlyCost(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'branchId' => ['required', 'string'],
                'itemId' => ['required', 'string'],
                'yearMonth' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            ]);

            $this->assertBranchAssigned($data['branchId']);
            $actor = $request->user();
            $companyId = (string) ($actor->company_id ?: AsabTenant::currentCompanyId());

            $cost = $this->monthlyCosts->approve(
                $companyId,
                $data['branchId'],
                $data['itemId'],
                $data['yearMonth'],
                (string) $actor->id,
            );

            return $this->ok($cost->toArray());
        });
    }
}

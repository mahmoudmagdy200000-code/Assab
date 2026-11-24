<?php

namespace Modules\Admin\Services;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Models\ConsumptionDailyCoverage;
use Modules\Inventory\Models\InventoryMonthlyCost;
use Modules\Inventory\Models\InventoryStockMovement;

/**
 * 05-CONSUMPTION-IMPORT.md — Waste Rate KPI calculation:
 * Waste Rate % = Final Waste Cost / (Normal Consumption Cost + Final Waste Cost) * 100
 * Uses posted_at for Final Waste movements and monthly approved cost for consumption.
 */
class WasteKpiService
{
    public function calculateForBranch(string $companyId, string $branchId, string $dateFrom, string $dateTo): array
    {
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->endOfDay();
        $expectedDays = iterator_count(CarbonPeriod::create($from->toDateString(), $to->toDateString()));

        $coverages = ConsumptionDailyCoverage::with('lines')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', 'CONFIRMED')
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('business_date')
            ->get();

        $completeDays = $coverages->where('day_status', 'COMPLETE')->count();
        $partialDays = $coverages->where('day_status', 'PARTIAL')->count();

        if ($coverages->isEmpty()) {
            return $this->unavailable($branchId, $dateFrom, $dateTo, 'بيانات الاستهلاك غير متاحة للفترة المحددة', $completeDays, $expectedDays);
        }
        if ($partialDays > 0 || $coverages->count() < $expectedDays) {
            return $this->unavailable($branchId, $dateFrom, $dateTo, 'تغطية أيام الاستهلاك غير مكتملة (توجد أيام جزئية أو مفقودة)', $completeDays, $expectedDays);
        }

        $normalConsumptionCostHalalas = 0;
        $costRefs = [];

        foreach ($coverages as $cov) {
            $ym = Carbon::parse($cov->business_date)->format('Y-m');
            foreach ($cov->lines as $line) {
                $normalQty = (float) $line->normal_consumed_qty;
                if ($normalQty <= 0.0) {
                    continue;
                }
                $costRow = InventoryMonthlyCost::where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->where('item_id', $line->item_id)
                    ->where('year_month', $ym)
                    ->first();

                if (! $costRow || $costRow->status !== InventoryMonthlyCost::STATUS_APPROVED) {
                    return $this->unavailable($branchId, $dateFrom, $dateTo, 'التكلفة الشهرية غير معتمدة أو مؤقتة', $completeDays, $expectedDays);
                }

                $normalConsumptionCostHalalas += (int) round($normalQty * (int) $costRow->unit_cost_halalas);
                $costRefs[] = "{$ym}:rev{$costRow->revision}";
            }
        }

        $wasteMovements = InventoryStockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('movement_type', 'waste_out')
            ->whereBetween('posted_at', [$from, $to])
            ->get();

        $finalWasteCostHalalas = (int) $wasteMovements->sum('total_cost_halalas');
        $denominator = $normalConsumptionCostHalalas + $finalWasteCostHalalas;

        if ($denominator <= 0) {
            return $this->unavailable($branchId, $dateFrom, $dateTo, 'المقام صفري (لا يوجد استهلاك عادي أو هدر نهائي)', $completeDays, $expectedDays);
        }

        $ratePct = round(($finalWasteCostHalalas / $denominator) * 100, 2);

        return [
            'branchId' => $branchId,
            'branchName' => Branch::whereKey($branchId)->value('name') ?? '',
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'available' => true,
            'unavailableReason' => null,
            'wasteRatePct' => $ratePct,
            'finalWasteCostHalalas' => $finalWasteCostHalalas,
            'normalConsumptionCostHalalas' => $normalConsumptionCostHalalas,
            'denominatorHalalas' => $denominator,
            'completeDays' => $completeDays,
            'expectedDays' => $expectedDays,
            'coverageRefs' => $coverages->map(fn ($c) => "{$c->business_date->format('Y-m-d')}:rev{$c->revision}")->values()->all(),
            'costRefs' => array_values(array_unique($costRefs)),
            'wasteMovementIds' => $wasteMovements->pluck('id')->values()->all(),
        ];
    }

    private function unavailable(string $branchId, string $dateFrom, string $dateTo, string $reason, int $completeDays, int $expectedDays): array
    {
        return [
            'branchId' => $branchId,
            'branchName' => Branch::whereKey($branchId)->value('name') ?? '',
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'available' => false,
            'unavailableReason' => $reason,
            'wasteRatePct' => null,
            'finalWasteCostHalalas' => 0,
            'normalConsumptionCostHalalas' => 0,
            'denominatorHalalas' => 0,
            'completeDays' => $completeDays,
            'expectedDays' => $expectedDays,
            'coverageRefs' => [],
            'costRefs' => [],
            'wasteMovementIds' => [],
        ];
    }
}

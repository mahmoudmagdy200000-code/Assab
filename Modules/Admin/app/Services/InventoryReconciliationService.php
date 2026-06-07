<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;

/**
 * Daily inventory reconciliation snapshot + variance allocation
 * (MISSING_Dashboard §9). The daily inventory submission lives on an Operation
 * (module_key=inventory) payload; allocations are stored on that payload keyed by
 * date and posted as employee-statement debits.
 */
class InventoryReconciliationService
{
    /** A variance at/above this magnitude (% of expected) is flagged. */
    private const FLAG_PCT = 5.0;

    public function __construct(private readonly RealtimeBroadcaster $rt) {}

    /** §9.1 — read-only reconciliation snapshot for a branch + date. */
    public function snapshot(string $companyId, string $branchId, string $date): array
    {
        $op = $this->inventoryOp($companyId, $branchId, $date);
        $payload = $op?->payload ?? [];
        $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $storedAllocations = $payload['varianceAllocations'][$date] ?? [];

        $prices = $this->unitPrices($rawItems);
        $employeeNames = $this->employeeNamesFor($storedAllocations);

        $items = [];
        $totalVariance = 0;
        $unassigned = 0;

        foreach ($rawItems as $idx => $it) {
            $itemId = $this->itemId($it, $idx);
            $expected = (float) ($it['expectedQty'] ?? $it['expected'] ?? $it['systemQty'] ?? 0);
            $actual = (float) ($it['actualQty'] ?? $it['actual'] ?? $it['countedQty'] ?? 0);
            $varianceQty = round($expected - $actual, 3);
            $variancePct = $expected != 0.0 ? round($varianceQty / $expected * 100, 2) : 0.0;
            $unitPrice = (int) ($it['unitPriceHalalas'] ?? $it['priceHalalas'] ?? ($prices[$itemId] ?? 0));
            $varianceValue = (int) round(abs($varianceQty) * $unitPrice);

            $allocatedTo = array_map(fn (array $a) => [
                'employeeId' => $a['employeeId'],
                'employeeName' => $employeeNames[$a['employeeId']] ?? null,
                'qty' => $a['qty'],
                'valueHalalas' => (int) ($a['valueHalalas'] ?? round(((float) $a['qty']) * $unitPrice)),
            ], $storedAllocations[$itemId] ?? []);

            $allocatedValue = array_sum(array_column($allocatedTo, 'valueHalalas'));
            $totalVariance += $varianceValue;
            $unassigned += max(0, $varianceValue - $allocatedValue);

            $items[] = [
                'itemId' => $itemId,
                'itemName' => $it['name'] ?? ($it['itemName'] ?? '—'),
                'unit' => $it['unit'] ?? null,
                'expectedQty' => $expected,
                'actualQty' => $actual,
                'varianceQty' => $varianceQty,
                'variancePct' => $variancePct,
                'varianceValueHalalas' => $varianceValue,
                'status' => (abs($variancePct) >= self::FLAG_PCT || ! empty($it['isAnomaly'])) ? 'flagged' : 'ok',
                'allocatedTo' => $allocatedTo,
            ];
        }

        return [
            'branchId' => $branchId,
            'branchName' => Branch::where('id', $branchId)->value('name'),
            'date' => $date,
            'items' => $items,
            'totalVarianceValueHalalas' => $totalVariance,
            'unassignedVarianceValueHalalas' => $unassigned,
        ];
    }

    /**
     * §9.2 — persist allocations + post employee debits, then return the snapshot.
     *
     * @param  array<int, array{itemId:string, allocations:array<int, array{employeeId:string, qty:float|int}>}>  $itemsAllocations
     */
    public function allocate(string $companyId, string $branchId, string $date, array $itemsAllocations, string $userId): array
    {
        $op = $this->inventoryOp($companyId, $branchId, $date);
        if (! $op) {
            throw new AsabException('NOT_FOUND', 'No inventory submission for that date', 'لا يوجد جرد لهذا التاريخ', 404);
        }

        $totalValue = DB::transaction(function () use ($op, $companyId, $branchId, $date, $itemsAllocations, $userId) {
            $payload = $op->payload ?? [];
            $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            $prices = $this->unitPrices($rawItems);
            $stored = $payload['varianceAllocations'][$date] ?? [];
            $total = 0;

            foreach ($itemsAllocations as $ia) {
                $itemId = (string) $ia['itemId'];
                $unitPrice = (int) ($prices[$itemId] ?? 0);
                $rows = [];
                foreach (($ia['allocations'] ?? []) as $a) {
                    $emp = Employee::where('company_id', $companyId)->where('branch_id', $branchId)
                        ->where(fn ($q) => $q->where('id', $a['employeeId'])->orWhere('emp_number', $a['employeeId']))->first();
                    if (! $emp) {
                        throw new AsabException('VALIDATION_ERROR', 'Employee not found in branch', 'الموظف غير موجود في الفرع', 422, [
                            'employeeId' => ['unknown employee '.$a['employeeId']],
                        ]);
                    }
                    $qty = (float) $a['qty'];
                    $value = (int) round($qty * $unitPrice);
                    $total += $value;
                    $rows[] = ['employeeId' => $emp->id, 'qty' => $qty, 'valueHalalas' => $value];

                    EmployeeMovement::create([
                        'employee_id' => $emp->id,
                        'movement_date' => $date,
                        'description' => 'تحميل فرق جرد يومي — '.($itemId),
                        'movement_type' => 'debit',
                        'amount' => $value,
                        'ref_operation_id' => $op->id,
                        'created_by_id' => $userId,
                    ]);
                }
                $stored[$itemId] = $rows;
            }

            $payload['varianceAllocations'][$date] = $stored;
            $op->update(['payload' => $payload]);

            return $total;
        });

        $this->rt->inventoryVarianceAllocated($branchId, $date, $totalValue);

        return $this->snapshot($companyId, $branchId, $date);
    }

    private function inventoryOp(string $companyId, string $branchId, string $date): ?Operation
    {
        return Operation::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('module_key', 'inventory')
            ->whereDate('operation_date', $date)
            ->orderByDesc('operation_date')
            ->first();
    }

    /** @return array<string,int> itemId → unit price (halalas) from inline payload or catalog */
    private function unitPrices(array $rawItems): array
    {
        $ids = [];
        foreach ($rawItems as $idx => $it) {
            $ids[] = $this->itemId($it, $idx);
        }
        $catalog = InventoryCatalogItem::whereIn('id', array_filter($ids))->pluck('unit_price', 'id');

        $prices = [];
        foreach ($rawItems as $idx => $it) {
            $id = $this->itemId($it, $idx);
            $prices[$id] = (int) ($it['unitPriceHalalas'] ?? $it['priceHalalas'] ?? ($catalog[$id] ?? 0));
        }

        return $prices;
    }

    /** @return array<string,?string> employeeId → name for all stored allocations */
    private function employeeNamesFor(array $storedAllocations): array
    {
        $ids = collect($storedAllocations)->flatten(1)->pluck('employeeId')->filter()->unique()->values();

        return $ids->isEmpty() ? [] : Employee::whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    private function itemId(array $it, int $idx): string
    {
        return (string) ($it['itemId'] ?? $it['catalogItemId'] ?? $it['id'] ?? ('row-'.$idx));
    }
}

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

    /** Ledger category for daily-inventory variance charges (idempotency key). */
    public const CATEGORY = 'inventory_variance';

    private const EPSILON = 0.0001;

    public function __construct(
        private readonly RealtimeBroadcaster $rt,
        private readonly EmployeeAllocationService $employeeAllocations,
    ) {}

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
            // ACC-4.3 equation فتح + مشتريات − استهلاك − هدر ± تحويلات = إغلاق متوقّع.
            $opening = (float) ($it['opening'] ?? $it['openingQty'] ?? 0);
            $received = (float) ($it['received'] ?? $it['purchases'] ?? $it['purchasesQty'] ?? 0);
            $consumed = (float) ($it['consumed'] ?? $it['sales'] ?? $it['consumedQty'] ?? 0);
            $waste = (float) ($it['waste'] ?? $it['wasteQty'] ?? 0);
            $transfers = (float) ($it['transfers'] ?? $it['transfersQty'] ?? 0);
            $hasEquation = isset($it['opening']) || isset($it['openingQty']);
            $expectedClosing = round($opening + $received - $consumed - $waste + $transfers, 3);

            $actual = (float) ($it['actualQty'] ?? $it['actual'] ?? $it['countedQty'] ?? 0);
            // Expected closing drives the variance when the equation terms exist;
            // otherwise fall back to the branch-supplied expected/system figure.
            $expected = $hasEquation ? $expectedClosing : (float) ($it['expectedQty'] ?? $it['expected'] ?? $it['systemQty'] ?? 0);
            $varianceQty = round($expected - $actual, 3);
            $variancePct = $expected != 0.0 ? round($varianceQty / $expected * 100, 2) : 0.0;
            $unitPrice = (int) ($it['unitPriceHalalas'] ?? $it['priceHalalas'] ?? ($prices[$itemId] ?? 0));
            $varianceValue = (int) round(abs($varianceQty) * $unitPrice);

            $minLevel = isset($it['minLevel']) ? (float) $it['minLevel'] : (isset($it['minQty']) ? (float) $it['minQty'] : null);
            $stockStatus = $this->stockStatus($actual, $minLevel);

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
                // ACC-4.3 daily equation terms.
                'opening' => $opening,
                'received' => $received,
                'consumed' => $consumed,
                'waste' => $waste,
                'transfers' => $transfers,
                // Only when the equation terms are real. Emitting the computed
                // closing for a payload that carries no opening balance put a
                // second, different «متوقع» on the same row as the one the
                // variance was calculated from — the row visibly failed to add
                // up (prod E2E 2026-07-31: expectedClosing −0.5 beside
                // expectedQty −1.5 and varianceQty −4.5).
                'expectedClosing' => $hasEquation ? $expectedClosing : null,
                'actualClosing' => $actual,
                'equationMatch' => $hasEquation ? abs($expectedClosing - $actual) < self::EPSILON : null,
                'minLevel' => $minLevel,
                'stockStatus' => $stockStatus,
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

            // Idempotency (T07.5): re-allocating the same op+date used to *add*
            // a second set of debits while overwriting the payload record, so
            // repeat saves silently multiplied the charge. Reverse this op+date's
            // prior debits before re-inserting; the allocation is now replaceable.
            EmployeeMovement::where('ref_operation_id', $op->id)
                ->where('category', self::CATEGORY)
                ->whereDate('movement_date', $date)
                ->delete();

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
                        'category' => self::CATEGORY,
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

    /** حرج / منخفض / طبيعي from the count against the item's minimum level. */
    private function stockStatus(float $actual, ?float $minLevel): array
    {
        if ($minLevel === null || $minLevel <= 0.0) {
            return ['key' => 'normal', 'labelAr' => 'طبيعي'];
        }

        return match (true) {
            $actual <= 0.0 || $actual < $minLevel * 0.5 => ['key' => 'critical', 'labelAr' => 'حرج'],
            $actual < $minLevel => ['key' => 'low', 'labelAr' => 'منخفض'],
            default => ['key' => 'normal', 'labelAr' => 'طبيعي'],
        };
    }
}

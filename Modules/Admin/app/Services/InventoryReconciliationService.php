<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Enums\InventorySessionStatus;
use Modules\Inventory\Enums\MonthlyInventoryStatus;
use Modules\Inventory\Models\ConsumptionDailyCoverage;
use Modules\Inventory\Models\ConsumptionDailyLine;
use Modules\Inventory\Models\InventoryMonthlyCost;
use Modules\Inventory\Models\InventorySession;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Inventory\Models\MonthlyInventory;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;

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
    public function snapshot(string|array $companyId, string $branchId, string $date): array
    {
        $op = $this->inventoryOp($companyId, $branchId, $date);
        $payload = $op?->payload ?? [];
        $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $storedAllocations = $payload['varianceAllocations'][$date] ?? [];

        $prices = $this->unitPrices($rawItems, $branchId, $date);
        $employeeNames = $this->employeeNamesFor($storedAllocations);

        $confirmedCoverageIds = ConsumptionDailyCoverage::where('branch_id', $branchId)
            ->whereDate('business_date', $date)
            ->where('status', 'CONFIRMED')
            ->pluck('id');
        $importedConsumptionByItem = $confirmedCoverageIds->isNotEmpty()
            ? ConsumptionDailyLine::whereIn('coverage_id', $confirmedCoverageIds)
                ->get()
                ->groupBy('item_id')
                ->map(fn ($rows) => round((float) $rows->sum('normal_consumed_qty'), 3))
                ->all()
            : [];
        $postedWasteByItem = InventoryStockMovement::where('branch_id', $branchId)
            ->where('movement_type', 'waste_out')
            ->whereDate('posted_at', $date)
            ->get()
            ->groupBy('item_id')
            ->map(fn ($rows) => round(abs((float) $rows->sum('qty_delta')), 3))
            ->all();

        $items = [];
        $totalVariance = 0;
        $unassigned = 0;

        foreach ($rawItems as $idx => $it) {
            $itemId = $this->itemId($it, $idx);
            // ACC-4.3 equation فتح + مشتريات − استهلاك − هدر ± تحويلات = إغلاق متوقّع.
            $opening = (float) ($it['opening'] ?? $it['openingQty'] ?? 0);
            $received = (float) ($it['received'] ?? $it['purchases'] ?? $it['purchasesQty'] ?? 0);
            $consumed = (float) ($it['consumed'] ?? $it['sales'] ?? $it['consumedQty'] ?? ($importedConsumptionByItem[$itemId] ?? 0));
            $waste = (float) ($it['waste'] ?? $it['wasteQty'] ?? ($postedWasteByItem[$itemId] ?? 0));
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
    public function allocate(string|array $companyId, string $branchId, string $date, array $itemsAllocations, string $userId): array
    {
        $op = $this->inventoryOp($companyId, $branchId, $date);
        if (! $op) {
            throw new AsabException('NOT_FOUND', 'No inventory submission for that date', 'لا يوجد جرد لهذا التاريخ', 404);
        }

        $companyIds = array_values(array_unique(array_map('strval', (array) $companyId)));
        $totalValue = DB::transaction(function () use ($op, $companyIds, $branchId, $date, $itemsAllocations, $userId) {
            // Serialize replacements for this inventory operation before reading
            // its current allocation or touching employee-ledger rows.
            $op = Operation::whereKey($op->id)
                ->whereIn('company_id', $companyIds)
                ->where('branch_id', $branchId)
                ->where('module_key', 'inventory')
                ->whereDate('operation_date', $date)
                ->lockForUpdate()
                ->first();
            if (! $op) {
                throw new AsabException('NOT_FOUND', 'No inventory submission for that date', 'لا يوجد جرد لهذا التاريخ', 404);
            }

            if (in_array($op->status, [Operation::STATUS_FINAL, Operation::STATUS_REJECTED], true)) {
                throw new AsabException('OP_ALREADY_FINAL', 'Operation is locked and can no longer be modified', 'لا يمكن تعديل جرد مُغلق', 409);
            }

            $payload = $op->payload ?? [];
            $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            $prices = $this->unitPrices($rawItems, $branchId, $date);
            $shortagesByItem = [];
            foreach ($rawItems as $idx => $it) {
                $id = $this->itemId($it, $idx);
                $opening = (float) ($it['opening'] ?? $it['openingQty'] ?? 0);
                $received = (float) ($it['received'] ?? $it['purchases'] ?? $it['purchasesQty'] ?? 0);
                $consumed = (float) ($it['consumed'] ?? $it['sales'] ?? $it['consumedQty'] ?? 0);
                $waste = (float) ($it['waste'] ?? $it['wasteQty'] ?? 0);
                $transfers = (float) ($it['transfers'] ?? $it['transfersQty'] ?? 0);
                $hasEq = isset($it['opening']) || isset($it['openingQty']);
                $expected = $hasEq
                    ? round($opening + $received - $consumed - $waste + $transfers, 3)
                    : (float) ($it['expectedQty'] ?? $it['expected'] ?? $it['systemQty'] ?? 0);
                $actual = (float) ($it['actualQty'] ?? $it['actual'] ?? $it['countedQty'] ?? 0);
                $shortagesByItem[$id] = max(0.0, round($expected - $actual, 3));
            }
            $total = 0;

            $resolvedAllocations = [];
            $employeeIds = [];

            foreach ($itemsAllocations as $ia) {
                $itemId = (string) $ia['itemId'];
                $unitPrice = (int) ($prices[$itemId] ?? 0);
                $rows = [];
                $allocatedQtySum = 0.0;
                foreach (($ia['allocations'] ?? []) as $a) {
                    $needle = (string) $a['employeeId'];
                    $emp = Employee::whereIn('company_id', $companyIds)
                        ->where('branch_id', $branchId)
                        ->where(fn ($q) => $q->where('id', $needle)->orWhere('emp_number', $needle))
                        ->first();
                    if (! $emp) {
                        throw new AsabException('VALIDATION_ERROR', 'Employee not found in branch', 'الموظف غير موجود في الفرع', 422, [
                            'employeeId' => ['unknown employee '.$needle],
                        ]);
                    }

                    $employeeIds[] = (string) $emp->id;
                    $qty = (float) $a['qty'];
                    $allocatedQtySum += $qty;
                    $value = (int) round($qty * $unitPrice);
                    $rows[] = ['employee' => $emp, 'itemId' => $itemId, 'qty' => $qty, 'valueHalalas' => $value];
                }

                if (isset($shortagesByItem[$itemId]) && $shortagesByItem[$itemId] > 0 && ($allocatedQtySum - $shortagesByItem[$itemId]) > self::EPSILON) {
                    throw new AsabException('VALIDATION_ERROR', 'Allocated quantity exceeds item shortage', 'الكمية المحمّلة تتجاوز مقدار العجز الفعلي للصنف', 422, [
                        'allocations' => ['allocated qty exceeds shortage for '.$itemId],
                    ]);
                }

                $resolvedAllocations[] = ['itemId' => $itemId, 'rows' => $rows];
            }

            $employeeIds = collect($employeeIds)->filter()->unique()->sort()->values()->all();
            $lockedEmployees = Employee::withTrashed()
                ->whereIn('id', $employeeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (Employee $employee) => (string) $employee->id);

            foreach ($resolvedAllocations as $allocation) {
                foreach ($allocation['rows'] as $row) {
                    $lockedEmployee = $lockedEmployees->get((string) $row['employee']->id);
                    if (! $lockedEmployee || $lockedEmployee->trashed()
                        || ! in_array((string) $lockedEmployee->company_id, $companyIds, true)
                        || (string) $lockedEmployee->branch_id !== $branchId) {
                        throw new AsabException('VALIDATION_ERROR', 'Employee not found in branch', 'الموظف غير موجود في الفرع', 422, [
                            'employeeId' => ['employee is no longer assigned to this branch'],
                        ]);
                    }
                }
            }

            // Stage proposed allocations on the operation payload; employee debits
            // and inventory stock adjustments post once upon Head final approval (INV-04B/C).
            $stored = [];
            foreach ($resolvedAllocations as $allocation) {
                $itemId = $allocation['itemId'];
                $rows = [];
                foreach ($allocation['rows'] as $row) {
                    $emp = $row['employee'];
                    $qty = $row['qty'];
                    $value = $row['valueHalalas'];
                    $total += $value;
                    $rows[] = ['employeeId' => $emp->id, 'qty' => $qty, 'valueHalalas' => $value];
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

    /**
     * INV-04B/C: Upon Head Accountant final approval, adjust branch stock once and
     * post approved employee variance debits once (atomic + idempotent).
     */
    public function postOnFinalApproval(Operation $op, AsabUser $actor): void
    {
        DB::transaction(function () use ($op, $actor): void {
            $payload = $op->payload ?? [];
            $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            $revision = (int) ($payload['revision'] ?? 1);
            $postedAt = now();
            $date = optional($op->operation_date)->toDateString() ?? $postedAt->toDateString();

            $prices = $this->unitPrices($rawItems, $op->branch_id, $date);

            // 1. Adjust branch inventory & record one stock movement per counted item.
            foreach ($rawItems as $idx => $it) {
                $itemId = $this->itemId($it, $idx);
                $actual = (float) ($it['actualQty'] ?? $it['actual'] ?? $it['countedQty'] ?? 0);
                $unitCost = (int) ($prices[$itemId] ?? $it['unitPriceHalalas'] ?? $it['priceHalalas'] ?? 0);
                $idempotencyKey = "inv_adj:{$op->id}:{$itemId}:rev{$revision}";

                if (InventoryStockMovement::where('idempotency_key', $idempotencyKey)->exists()) {
                    continue;
                }

                $branchItem = BranchItem::where('branch_id', $op->branch_id)->where('item_id', $itemId)->lockForUpdate()->first();
                $branchInv = BranchInventory::where('branch_id', $op->branch_id)->where('item_id', $itemId)->lockForUpdate()->first();
                $currentStock = (float) ($branchInv?->available_quantity ?? $branchItem?->quantity ?? 0);
                $delta = round($actual - $currentStock, 3);

                if ($branchInv) {
                    $branchInv->update([
                        'available_quantity' => $actual,
                        'last_inventory_update' => $postedAt,
                    ]);
                }
                if ($branchItem) {
                    $branchItem->update(['quantity' => $actual]);
                }

                InventoryStockMovement::create([
                    'idempotency_key' => $idempotencyKey,
                    'company_id' => $op->company_id,
                    'branch_id' => $op->branch_id,
                    'item_id' => $itemId,
                    'movement_type' => 'stocktake_adjustment',
                    'qty_delta' => $delta,
                    'unit_cost_halalas' => $unitCost,
                    'total_cost_halalas' => (int) round(abs($delta) * $unitCost),
                    'source_type' => $op->source_module ?? 'inventory',
                    'source_id' => $op->source_id,
                    'operation_id' => $op->id,
                    'revision' => $revision,
                    'posted_at' => $postedAt,
                    'created_by_id' => $actor->id,
                    'notes' => 'تعديل رصيد الجرد عند الاعتماد النهائي — '.$op->public_id,
                ]);
            }

            // 2. Post staged employee variance allocations once upon final approval.
            $allocationsByDate = $payload['varianceAllocations'] ?? [];
            if (! empty($allocationsByDate)) {
                $empIds = [];
                foreach ($allocationsByDate as $byItem) {
                    if (! is_array($byItem)) {
                        continue;
                    }
                    foreach ($byItem as $rows) {
                        foreach ((array) $rows as $row) {
                            $eid = (string) ($row['employeeId'] ?? '');
                            if ($eid !== '') {
                                $empIds[] = $eid;
                            }
                        }
                    }
                }
                $empIds = collect($empIds)->unique()->sort()->values()->all();
                if ($empIds !== []) {
                    Employee::withTrashed()
                        ->whereIn('id', $empIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                }

                $alreadyPosted = EmployeeMovement::where('ref_operation_id', $op->id)
                    ->where('category', self::CATEGORY)
                    ->whereNull('reversal_of_movement_id')
                    ->whereDoesntHave('reversal')
                    ->exists();

                if (! $alreadyPosted) {
                    foreach ($allocationsByDate as $allocDate => $byItem) {
                        if (! is_array($byItem)) {
                            continue;
                        }
                        foreach ($byItem as $itemId => $rows) {
                            $unitPrice = (int) ($prices[$itemId] ?? 0);
                            foreach ((array) $rows as $row) {
                                $empId = (string) ($row['employeeId'] ?? '');
                                $qty = (float) ($row['qty'] ?? 0);
                                $value = (int) ($row['valueHalalas'] ?? round($qty * $unitPrice));
                                if ($empId === '' || $value <= 0) {
                                    continue;
                                }
                                EmployeeMovement::create([
                                    'employee_id' => $empId,
                                    'movement_date' => $allocDate ?: $date,
                                    'description' => 'تحميل فرق جرد — '.$op->public_id.' — '.$itemId,
                                    'movement_type' => 'debit',
                                    'category' => self::CATEGORY,
                                    'amount' => $value,
                                    'ref_operation_id' => $op->id,
                                    'created_by_id' => $actor->id,
                                ]);
                            }
                        }
                    }
                }
            }

            // 3. Mark source mobile inventory record as finalized.
            if ($op->source_module === InventoryBridgeService::SOURCE && $op->source_id) {
                InventorySession::whereKey($op->source_id)->update([
                    'status' => InventorySessionStatus::APPROVED->value,
                    'approved_at' => $postedAt,
                    'approved_by' => $actor->id,
                ]);
            } elseif ($op->source_module === InventoryBridgeService::SOURCE_MONTHLY && $op->source_id) {
                MonthlyInventory::whereKey($op->source_id)->update([
                    'status' => MonthlyInventoryStatus::APPROVED->value,
                    'approved_at' => $postedAt,
                ]);
            }
        });
    }

    private function inventoryOp(string|array $companyId, string $branchId, string $date): ?Operation
    {
        return Operation::whereIn('company_id', (array) $companyId)
            ->where('branch_id', $branchId)
            ->where('module_key', 'inventory')
            ->whereDate('operation_date', $date)
            ->orderByDesc('operation_date')
            ->first();
    }

    /** @return array<string,int> itemId → unit price (halalas) from approved monthly weighted cost, inline payload, or catalog */
    private function unitPrices(array $rawItems, ?string $branchId = null, ?string $date = null): array
    {
        $ids = [];
        foreach ($rawItems as $idx => $it) {
            $ids[] = $this->itemId($it, $idx);
        }
        $cleanIds = array_values(array_filter($ids));
        $catalog = InventoryCatalogItem::whereIn('id', $cleanIds)->pluck('unit_price', 'id');

        $monthlyCosts = [];
        if ($branchId && $cleanIds !== []) {
            $ym = $date ? substr($date, 0, 7) : null;
            $monthlyCosts = InventoryMonthlyCost::where('branch_id', $branchId)
                ->whereIn('item_id', $cleanIds)
                ->where('status', InventoryMonthlyCost::STATUS_APPROVED)
                ->when($ym, fn ($q) => $q->where('year_month', '<=', $ym))
                ->orderByDesc('year_month')
                ->get()
                ->groupBy('item_id')
                ->map(fn ($rows) => (int) $rows->first()->unit_cost_halalas)
                ->all();
        }

        $prices = [];
        foreach ($rawItems as $idx => $it) {
            $id = $this->itemId($it, $idx);
            $prices[$id] = (int) ($monthlyCosts[$id] ?? $it['unitPriceHalalas'] ?? $it['priceHalalas'] ?? ($catalog[$id] ?? 0));
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

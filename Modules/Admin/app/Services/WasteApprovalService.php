<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Support\WasteEnums;
use Modules\Inventory\Enums\WasteDamageReportStatus;
use Modules\Inventory\Models\InventoryMonthlyCost;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Inventory\Models\WasteDamageReport;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;

/**
 * SRS ACC-5.3 / WASTE-01 — Accountant review & Head final-approval posting.
 *
 * Accountant review validates classification & owner approval for employee-charged
 * items and moves the operation to `approved` (awaiting Head Accountant).
 * Stock exit (`InventoryStockMovement` + BranchInventory deduction) and eligible
 * employee ledger debits (`خصم هدر`) are posted atomically and idempotently ONLY
 * when the Head Accountant executes `finalApprove` (WASTE-01B/C/D).
 */
class WasteApprovalService
{
    public function __construct(
        private readonly OperationService $operations,
        private readonly EmployeeAllocationService $allocations,
    ) {}

    /**
     * Validate + normalise the employee allocations for one waste product
     * (ACC-5.3). For a «موظف» product the amounts must sum to the product's
     * value; a «مطعم» product carries no employee charge.
     *
     * @param  array<int, array{employeeId?:string, empNumber?:string, amountHalalas:int|string}>  $empAllocs
     * @return array<int, array{employeeId:string, employeeName:string, amountHalalas:int}>
     */
    public function normaliseAllocations(Operation $op, array $product, array $empAllocs): array
    {
        $rows = [];
        $amounts = [];
        foreach ($empAllocs as $a) {
            if (! isset($a['amountHalalas']) || (int) $a['amountHalalas'] < 1) {
                throw new AsabException('VALIDATION_ERROR', 'Allocation amount must be a positive integer', 'قيمة التخصيص يجب أن تكون رقماً موجباً', 422, [
                    'empAllocs' => ['each allocation needs amountHalalas >= 1'],
                ]);
            }
            $emp = $this->allocations->resolveEmployee($op, $a);
            $amount = (int) $a['amountHalalas'];
            $amounts[] = $amount;
            $rows[] = ['employeeId' => $emp->id, 'employeeName' => $emp->name, 'amountHalalas' => $amount];
        }

        if (($product['responsibility'] ?? null) === WasteEnums::RESP_EMPLOYEE) {
            $this->allocations->assertSum($amounts, (int) ($product['value'] ?? 0));
        }

        return $rows;
    }

    /** Accountant review approval: validates owner approval for employee items, moves to `approved`. */
    public function approve(Operation $op, AsabUser $actor): Operation
    {
        return DB::transaction(function () use ($op, $actor) {
            $this->assertOwnerApprovalIfEmployeeCharged($op);

            return $this->operations->approve($op, $actor);
        });
    }

    /**
     * @param  string[]  $ids
     * @return array{approved: string[], failed: array<int, array{id:string, code:string}>}
     */
    public function bulkApprove(array $ids, AsabUser $actor): array
    {
        $approved = [];
        $failed = [];
        foreach ($ids as $id) {
            $op = Operation::where('module_key', 'waste')->where('id', $id)->first();
            if (! $op) {
                $failed[] = ['id' => $id, 'code' => 'NOT_FOUND'];

                continue;
            }
            try {
                $this->approve($op, $actor);
                $approved[] = $op->public_id;
            } catch (AsabException $e) {
                $failed[] = ['id' => $id, 'code' => $e->errorCode];
            }
        }

        return ['approved' => $approved, 'failed' => $failed];
    }

    /**
     * WASTE-01B/C/D: Executed atomically inside OperationService::finalApprove().
     * Deducts branch stock once per item and posts eligible employee debits once.
     */
    public function postOnFinalApproval(Operation $op, AsabUser $actor): void
    {
        $this->assertOwnerApprovalIfEmployeeCharged($op);

        $payload = $op->payload ?? [];
        $products = is_array($payload['products'] ?? null) ? $payload['products'] : [];
        $revision = (int) ($payload['revision'] ?? 1);
        $postedAt = now();
        $yearMonth = $postedAt->format('Y-m');

        foreach ($products as $idx => &$product) {
            $qty = (float) ($product['qty'] ?? 0);
            $itemId = (string) ($product['itemId'] ?? '');
            $idempotencyKey = "waste_out:{$op->id}:{$idx}:rev{$revision}";

            if (InventoryStockMovement::where('idempotency_key', $idempotencyKey)->exists()) {
                continue;
            }

            // Resolve effective weighted cost at approval time (prefer current/latest APPROVED,
            // then PROVISIONAL, then payload price) and freeze it on the waste record & employee charges.
            $costRow = $itemId !== ''
                ? (InventoryMonthlyCost::where('company_id', $op->company_id)
                    ->where('branch_id', $op->branch_id)
                    ->where('item_id', $itemId)
                    ->where('year_month', '<=', $yearMonth)
                    ->where('status', InventoryMonthlyCost::STATUS_APPROVED)
                    ->orderByDesc('year_month')
                    ->first()
                    ?? InventoryMonthlyCost::where('company_id', $op->company_id)
                        ->where('branch_id', $op->branch_id)
                        ->where('item_id', $itemId)
                        ->where('year_month', '<=', $yearMonth)
                        ->orderByDesc('year_month')
                        ->first())
                : null;

            $unitCostHalalas = $costRow
                ? (int) $costRow->unit_cost_halalas
                : (int) ($product['unitPriceHalalas'] ?? ($qty > 0 ? round(((int) ($product['value'] ?? 0)) / $qty) : 0));

            $totalCostHalalas = $qty > 0 && $unitCostHalalas > 0
                ? (int) round($qty * $unitCostHalalas)
                : (int) ($product['value'] ?? 0);

            $product['postedUnitCostHalalas'] = $unitCostHalalas;
            $product['postedTotalCostHalalas'] = $totalCostHalalas;
            $product['costSource'] = $costRow
                ? ($costRow->status === InventoryMonthlyCost::STATUS_APPROVED ? 'monthly_approved' : 'monthly_provisional')
                : 'operation_payload';
            $product['costRevision'] = $costRow?->revision;
            $product['costFrozenAt'] = $postedAt->toIso8601String();

            if (($product['responsibility'] ?? null) === WasteEnums::RESP_EMPLOYEE && ! empty($product['empAllocs']) && $totalCostHalalas > 0) {
                $origAllocs = $product['empAllocs'];
                if (! isset($product['originalEmpAllocs'])) {
                    $product['originalEmpAllocs'] = $origAllocs;
                    $product['originalValue'] = (int) ($product['value'] ?? 0);
                }
                $origSum = array_sum(array_map(fn ($a) => max(0, (int) ($a['amountHalalas'] ?? 0)), $origAllocs));
                $countAllocs = count($origAllocs);
                $running = 0;
                $recomputed = [];
                foreach ($origAllocs as $aIdx => $allocRow) {
                    if ($aIdx === $countAllocs - 1) {
                        $newAmt = max(1, $totalCostHalalas - $running);
                    } else {
                        $share = $origSum > 0
                            ? (int) round($totalCostHalalas * (((int) ($allocRow['amountHalalas'] ?? 0)) / $origSum))
                            : (int) floor($totalCostHalalas / $countAllocs);
                        $newAmt = max(1, $share);
                        $running += $newAmt;
                    }
                    $allocRow['amountHalalas'] = $newAmt;
                    $recomputed[] = $allocRow;
                }
                $product['empAllocs'] = $recomputed;
                $product['value'] = $totalCostHalalas;
            }

            if ($itemId !== '' && $qty > 0) {
                $branchInv = BranchInventory::where('branch_id', $op->branch_id)
                    ->where('item_id', $itemId)
                    ->lockForUpdate()
                    ->first();
                if ($branchInv) {
                    $newQty = max(0.0, round((float) $branchInv->available_quantity - $qty, 3));
                    $branchInv->update([
                        'available_quantity' => $newQty,
                        'last_inventory_update' => $postedAt,
                    ]);
                }

                $branchItem = BranchItem::where('branch_id', $op->branch_id)
                    ->where('item_id', $itemId)
                    ->lockForUpdate()
                    ->first();
                if ($branchItem) {
                    $branchItem->update([
                        'quantity' => max(0.0, round((float) $branchItem->quantity - $qty, 3)),
                    ]);
                }
            }

            InventoryStockMovement::create([
                'idempotency_key' => $idempotencyKey,
                'company_id' => $op->company_id,
                'branch_id' => $op->branch_id,
                'item_id' => $itemId !== '' ? $itemId : $op->id,
                'movement_type' => 'waste_out',
                'qty_delta' => -1 * abs($qty),
                'unit_cost_halalas' => $unitCostHalalas,
                'total_cost_halalas' => $totalCostHalalas,
                'source_type' => $op->source_module ?? 'waste',
                'source_id' => $op->source_id,
                'operation_id' => $op->id,
                'revision' => $revision,
                'cost_source' => $product['costSource'],
                'cost_revision' => $product['costRevision'],
                'posted_at' => $postedAt,
                'created_by_id' => $actor->id,
                'notes' => 'خروج هدر معتمد نهائياً — '.$op->public_id.' — '.($product['name'] ?? ''),
            ]);
        }
        unset($product);

        $payload['products'] = $products;
        $payload['postedAt'] = $postedAt->toIso8601String();
        $op->update(['payload' => $payload]);

        $this->postCharges($op->fresh(), $actor);

        if ($op->source_module === WasteBridgeService::SOURCE && $op->source_id) {
            WasteDamageReport::whereKey($op->source_id)->update([
                'status' => WasteDamageReportStatus::COMPLETED->value,
                'approved_at' => $postedAt,
            ]);
        }
    }

    /**
     * WASTE-01B/C: Any employee-attributed waste item requires valid Brand Owner
     * approval matching the current revision before Accountant or Head approval.
     */
    private function assertOwnerApprovalIfEmployeeCharged(Operation $op): void
    {
        $payload = $op->payload ?? [];
        $products = is_array($payload['products'] ?? null) ? $payload['products'] : [];
        $hasEmployeeItem = false;
        foreach ($products as $p) {
            if (($p['responsibility'] ?? null) === WasteEnums::RESP_EMPLOYEE) {
                $hasEmployeeItem = true;
                break;
            }
        }

        if (! $hasEmployeeItem) {
            return;
        }

        $ownerApproval = $payload['ownerApproval'] ?? null;
        $currentRevision = (int) ($payload['revision'] ?? 1);

        if (! is_array($ownerApproval) || empty($ownerApproval['approved']) || ! empty($ownerApproval['rejected'])) {
            throw new AsabException(
                'OWNER_APPROVAL_REQUIRED',
                'Brand owner approval is required before charging an employee for waste/damage',
                'موافقة مالك العلامة مطلوبة قبل تحميل الموظف قيمة الهدر أو التالف',
                409
            );
        }

        if ((int) ($ownerApproval['revision'] ?? 0) !== $currentRevision) {
            throw new AsabException(
                'OWNER_APPROVAL_STALE',
                'Brand owner approval does not match the current revision',
                'موافقة المالك مرتبطة بنسخة سابقة — يلزم إعادة موافقة المالك على النسخة الحالية',
                409
            );
        }
    }

    /**
     * Post one «خصم هدر» debit per employee allocation of every «موظف» product.
     * «مطعم» products post nothing. Idempotent via the allocation service's
     * reverse-then-insert.
     */
    private function postCharges(Operation $op, AsabUser $actor): void
    {
        $products = $op->payload['products'] ?? [];
        $rows = [];
        foreach ($products as $product) {
            if (($product['responsibility'] ?? null) !== WasteEnums::RESP_EMPLOYEE) {
                continue;
            }
            foreach (($product['empAllocs'] ?? []) as $a) {
                if (! isset($a['amountHalalas'], $a['employeeId']) || (int) $a['amountHalalas'] < 1) {
                    continue;
                }
                $emp = $this->allocations->resolveEmployee($op, $a);
                $rows[] = [
                    'employee' => $emp,
                    'amountHalalas' => (int) $a['amountHalalas'],
                    'label' => WasteEnums::CATEGORY_LABEL_AR.' — '.$op->public_id.' — '.($product['name'] ?? '—'),
                ];
            }
        }

        if ($rows !== []) {
            $this->allocations->post($op, WasteEnums::CATEGORY, WasteEnums::CATEGORY_LABEL_AR, $rows, $actor);
        } else {
            $this->allocations->reverse($op, WasteEnums::CATEGORY);
        }
    }
}

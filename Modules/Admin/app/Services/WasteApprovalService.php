<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Support\WasteEnums;

/**
 * SRS ACC-5.3 — «خصم هدر». When a waste record is approved, every product whose
 * responsibility is «موظف» charges its allocated employees on the Employee
 * Account ledger. Before T07 the responsibility toggle and its allocations were
 * stored dead: approve only flipped the status, so the charge never reached the
 * ledger and the «منه على موظفين» KPI could never be true.
 *
 * The status change and the ledger post share one transaction, and posting is
 * idempotent (the allocation service reverses any prior «خصم هدر» set for the
 * operation first), so a re-approve or retry never double-charges.
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

    /** Approve a waste op and post its «خصم هدر» debits atomically. */
    public function approve(Operation $op, AsabUser $actor): Operation
    {
        return DB::transaction(function () use ($op, $actor) {
            $approved = $this->operations->approve($op, $actor);
            $this->postCharges($approved, $actor);

            return $approved;
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

        // post() reverses any prior set first; when there is nothing to charge
        // we still reverse so a re-approve with fewer allocations leaves no stale debits.
        if ($rows !== []) {
            $this->allocations->post($op, WasteEnums::CATEGORY, WasteEnums::CATEGORY_LABEL_AR, $rows, $actor);
        } else {
            $this->allocations->reverse($op, WasteEnums::CATEGORY);
        }
    }
}

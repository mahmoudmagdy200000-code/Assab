<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift;

/**
 * SRS ACC-6.1 — the live tab's «الطلبات حتى الآن / المبيعات المتوقّعة» need a
 * writer. Until the MOB-1.6 POS bridge (T08.12) lands, the dashboard's own sales
 * submissions feed the open shift: each sales operation for a branch with an
 * open (or late) shift bumps its order count and running sales.
 *
 * Interim: one shift per branch, so the branch's current open shift is the
 * target. Called inside the operation-creation transaction so a rolled-back
 * upload never leaves a phantom bump.
 */
class ShiftSalesFeed
{
    public function record(Operation $op): void
    {
        if ($op->module_key !== 'sales' || $op->branch_id === null) {
            return;
        }

        $shift = Shift::where('company_id', $op->company_id)
            ->where('branch_id', $op->branch_id)
            ->whereIn('status', ['active', 'late'])
            ->orderByDesc('started_at')
            ->first();

        if ($shift === null) {
            return;
        }

        $shift->increment('orders_count');
        $shift->increment('sales_amount', max(0, (int) $op->amount));
    }
}

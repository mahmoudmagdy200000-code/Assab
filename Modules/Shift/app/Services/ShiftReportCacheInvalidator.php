<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Facades\DB;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;

/** Report changes can affect same-day and carry-over manager views. Never invalidate on rollback. */
class ShiftReportCacheInvalidator
{
    public function cashierReport(CashierShift $shift): void
    {
        $this->branch((string) $shift->shift()->value('branch_id'));
    }

    public function branch(string $branchId): void
    {
        DB::afterCommit(function () use ($branchId) {
            $reader = app(BranchManagerShiftService::class);
            BranchManagerShift::where('branch_id', $branchId)->orderBy('id')->chunkById(100, function ($days) use ($reader) {
                foreach ($days as $day) {
                    $reader->clearShiftCaches($day);
                }
            });
        });
    }
}

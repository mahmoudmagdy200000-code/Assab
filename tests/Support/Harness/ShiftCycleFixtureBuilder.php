<?php

namespace Tests\Support\Harness;

use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Services\CashierShiftStartService;

final class ShiftCycleFixtureBuilder
{
    public function assigned(AssabHarnessFixture $fixture): CashierShift
    {
        return CashierShift::create([
            'cashier_id' => $fixture->cashier()->id,
            'shift_id' => $fixture->shiftTemplate()->id,
            'shift_date' => today()->toDateString(),
            'status' => ShiftStatus::NOT_STARTED,
            'opening_balance' => '0.00',
            'assigned_by' => $fixture->branchManager()->id,
        ]);
    }

    public function start(CashierShift $assignment): CashierShift
    {
        return app(CashierShiftStartService::class)->startShift($assignment);
    }
}

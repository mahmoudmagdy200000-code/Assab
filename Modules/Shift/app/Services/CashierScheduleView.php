<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Collection;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\Shift;

/** Current/upcoming active assignments; completed historical windows stay in history. */
final class CashierScheduleView
{
    public function forCashier(Cashier $cashier): Collection
    {
        return Shift::query()->where('branch_id', $cashier->branch_id)->where('is_active', true)
            ->whereHas('cashierShifts', fn ($query) => $query->where('cashier_id', $cashier->id)
                ->whereDate('shift_date', '>=', today())->whereIn('status', ['not_started', 'in_progress', 'reassigned']))
            ->orderBy('start_time')->orderBy('id')->get()
            ->map(fn (Shift $shift) => ['id' => $shift->id, 'name' => $shift->name,
                'start_time' => $shift->start_time?->format('H:i'), 'end_time' => $shift->end_time?->format('H:i')]);
    }
}

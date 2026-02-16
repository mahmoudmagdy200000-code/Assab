<?php

namespace Modules\Inventory\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Inventory\Models\DailyInventorySchedule;

/**
 * Daily inventory schedule management is restricted to Account Manager (or equivalent).
 * When your auth layer has an Account Manager role/guard, add a check here (e.g. $user->hasRole('account_manager')).
 */
class DailyInventorySchedulePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, DailyInventorySchedule $schedule): bool
    {
        return true;
    }

    public function create(Authenticatable $user): bool
    {
        return true;
    }

    public function update(Authenticatable $user, DailyInventorySchedule $schedule): bool
    {
        return true;
    }

    public function delete(Authenticatable $user, DailyInventorySchedule $schedule): bool
    {
        return true;
    }
}

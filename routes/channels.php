<?php

use Illuminate\Support\Facades\Broadcast;
use Modules\Inventory\Models\MonthlyInventory;
use Modules\Inventory\Models\MonthlyInventoryStaff;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Private channel for user notifications
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('inventory.monthly.{inventoryId}', function ($user, $inventoryId) {
    $inventory = MonthlyInventory::query()
        ->select(['id', 'branch_id', 'created_by'])
        ->find($inventoryId);

    if (!$inventory) {
        return false;
    }

    if ((string) $inventory->created_by === (string) $user->getKey()) {
        return [
            'id' => $user->getKey(),
            'name' => $user->name ?? 'Unknown',
            'type' => $user->getMorphClass(),
        ];
    }

    $isStaffMember = MonthlyInventoryStaff::query()
        ->where('monthly_inventory_id', $inventoryId)
        ->where('user_id', $user->getKey())
        ->whereIn('user_type', [$user->getMorphClass(), get_class($user)])
        ->exists();

    if (!$isStaffMember) {
        return false;
    }

    return [
        'id' => $user->getKey(),
        'name' => $user->name ?? 'Unknown',
        'type' => $user->getMorphClass(),
    ];
});

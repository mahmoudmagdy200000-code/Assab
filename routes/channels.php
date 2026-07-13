<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Modules\FixedAssets\Models\Handover;
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

// Private channel for user notifications.
// NOTE: mobile identities are UUID strings (HasUuids). A prior `(int)` cast
// collapsed every id to 0, so `0 === 0` authorized ANY user onto ANY user's
// private channel (cross-user leak). Compare as strings, like the asab channel.
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (string) $user->getKey() === (string) $userId;
});

$authorizeInventoryChannel = function ($user, $inventoryId) {
    Log::info('Channel auth attempt', [
        'inventory_id' => $inventoryId,
        'user_id' => $user->getKey(),
        'user_type' => get_class($user),
        'morph_class' => $user->getMorphClass(),
    ]);

    $inventory = MonthlyInventory::query()
        ->select(['id', 'branch_id', 'created_by'])
        ->find($inventoryId);

    if (! $inventory) {
        Log::warning('Channel auth: inventory not found', ['inventory_id' => $inventoryId]);

        return false;
    }

    Log::info('Channel auth: comparing', [
        'inventory_created_by' => $inventory->created_by,
        'user_key' => $user->getKey(),
        'match' => (string) $inventory->created_by === (string) $user->getKey(),
    ]);

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

    Log::info('Channel auth: staff check', ['is_staff' => $isStaffMember]);

    if (! $isStaffMember) {
        return false;
    }

    return [
        'id' => $user->getKey(),
        'name' => $user->name ?? 'Unknown',
        'type' => $user->getMorphClass(),
    ];
};

// Inventory-level events (progress_saved, submitted)
Broadcast::channel('inventory.monthly.{inventoryId}', $authorizeInventoryChannel, ['guards' => ['sanctum']]);

// Product pending events (product.claimed, product.released)
Broadcast::channel('inventory.monthly.{inventoryId}.pending', $authorizeInventoryChannel, ['guards' => ['sanctum']]);

// Product completed events (product.updated)
Broadcast::channel('inventory.monthly.{inventoryId}.completed', $authorizeInventoryChannel, ['guards' => ['sanctum']]);

/*
|--------------------------------------------------------------------------
| ASAB real-time channels (BACKEND_API_SPEC.md §8) — `asab` guard.
| Channel base names match the spec; Echo adds the `private-` transport prefix.
|--------------------------------------------------------------------------
*/
Broadcast::channel('notifications.user.{userId}', function ($user, $userId) {
    return (string) $user->getKey() === (string) $userId;
}, ['guards' => ['asab']]);

Broadcast::channel('operations.brand.{brandId}', function ($user, $brandId) {
    if (method_exists($user, 'hasAsabRole') && $user->hasAsabRole('admin')) {
        return true;
    }

    return \Modules\Admin\Models\AsabBrand::query()
        ->whereKey($brandId)->where('company_id', $user->company_id)->exists();
}, ['guards' => ['asab']]);

Broadcast::channel('operations.company.{companyId}', function ($user, $companyId) {
    if (method_exists($user, 'hasAsabRole') && $user->hasAsabRole('admin')) {
        return true;
    }

    return (string) $user->company_id === (string) $companyId;
}, ['guards' => ['asab']]);

Broadcast::channel('reminders.branch.{branchId}', function ($user, $branchId) {
    if (method_exists($user, 'hasAsabRole') && $user->hasAsabRole('admin')) {
        return true;
    }

    return \Modules\Branch\Models\Branch::query()
        ->whereKey($branchId)->where('asab_company_id', $user->company_id)->exists();
}, ['guards' => ['asab']]);

// Live support chat (FE completion request §2.1) — opener or assigned agent only.
Broadcast::channel('chat.session.{sessionId}', function ($user, $sessionId) {
    return \Modules\Admin\Models\SupportChatSession::query()
        ->whereKey($sessionId)
        ->where('company_id', $user->company_id)
        ->where(function ($q) use ($user) {
            $q->where('user_id', $user->getKey())->orWhere('agent_user_id', $user->getKey());
        })
        ->exists();
}, ['guards' => ['asab']]);

// Fixed Assets handover session channel — only sender and recipient may subscribe
Broadcast::channel('handover-session.{sessionId}', function ($user, $sessionId) {
    $handover = Handover::query()
        ->select(['id', 'sender_id', 'recipient_type', 'recipient_id'])
        ->find($sessionId);

    if (! $handover) {
        return false;
    }

    $userKey = (string) $user->getKey();

    if ((string) $handover->sender_id === $userKey) {
        return true;
    }

    if (
        (string) $handover->recipient_id === $userKey
        && in_array($user->getMorphClass(), [$handover->recipient_type, get_class($user)], true)
    ) {
        return true;
    }

    return false;
}, ['guards' => ['sanctum']]);

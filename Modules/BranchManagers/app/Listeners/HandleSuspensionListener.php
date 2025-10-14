<?php

namespace Modules\BranchManagers\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\BranchManagers\Events\BranchManagerSuspendedEvent;
use Modules\BranchManagers\Notifications\AccountSuspendedNotification;

class HandleSuspensionListener
{
    public function handle(BranchManagerSuspendedEvent $event): void
    {
        // Revoke all tokens
        $event->manager->tokens()->delete();

        // Send suspension notification
        $event->manager->notify(new AccountSuspendedNotification($event->reason));

        Log::warning('Branch Manager suspended', [
            'manager_id' => $event->manager->id,
            'email' => $event->manager->email,
            'reason' => $event->reason,
        ]);
    }
}

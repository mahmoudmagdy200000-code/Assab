<?php

namespace Modules\BranchManagers\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\BranchManagers\Events\PasswordChangedEvent;

class NotifyPasswordChangedListener
{
    public function handle(PasswordChangedEvent $event): void
    {
        Log::info('Password changed for Branch Manager', [
            'manager_id' => $event->manager->id,
            'email' => $event->manager->email,
        ]);

        // Send email notification
        // $event->manager->notify(new PasswordChangedNotification());
    }
}

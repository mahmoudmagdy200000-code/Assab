<?php

namespace Modules\BranchManagers\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\BranchManagers\Events\BranchManagerCreatedEvent;
use Modules\BranchManagers\Notifications\WelcomeNotification;

class SendWelcomeNotificationListener
{
    public function handle(BranchManagerCreatedEvent $event): void
    {
        $event->manager->notify(new WelcomeNotification($event->defaultPassword));

        Log::info('Welcome notification sent to Branch Manager', [
            'manager_id' => $event->manager->id,
            'email' => $event->manager->email,
        ]);
    }
}

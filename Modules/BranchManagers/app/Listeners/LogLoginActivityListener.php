<?php

namespace Modules\BranchManagers\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\BranchManagers\Events\BranchManagerLoggedInEvent;

class LogLoginActivityListener
{
    public function handle(BranchManagerLoggedInEvent $event): void
    {
        Log::info('Branch Manager logged in', [
            'manager_id' => $event->manager->id,
            'email' => $event->manager->email,
            'ip_address' => $event->ipAddress,
            'timestamp' => now()->toDateTimeString(),
        ]);

        // You can store login history in database if needed
        // LoginHistory::create([...]);
    }
}

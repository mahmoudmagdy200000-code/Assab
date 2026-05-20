<?php

namespace Modules\BranchManagers\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\BranchManagers\Events\BranchManagerCreatedEvent;
use Modules\BranchManagers\Events\BranchManagerLoggedInEvent;
use Modules\BranchManagers\Events\BranchManagerSuspendedEvent;
use Modules\BranchManagers\Events\PasswordChangedEvent;
use Modules\BranchManagers\Listeners\HandleSuspensionListener;
use Modules\BranchManagers\Listeners\LogLoginActivityListener;
use Modules\BranchManagers\Listeners\NotifyPasswordChangedListener;
use Modules\BranchManagers\Listeners\SendWelcomeNotificationListener;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        BranchManagerCreatedEvent::class => [
            SendWelcomeNotificationListener::class,
        ],
        BranchManagerLoggedInEvent::class => [
            LogLoginActivityListener::class,
        ],
        PasswordChangedEvent::class => [
            NotifyPasswordChangedListener::class,
        ],
        BranchManagerSuspendedEvent::class => [
            HandleSuspensionListener::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}

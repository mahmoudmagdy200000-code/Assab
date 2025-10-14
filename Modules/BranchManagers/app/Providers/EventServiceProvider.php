<?php

namespace Modules\BranchManagers\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\BranchManagers\Events\{
    BranchManagerCreatedEvent,
    BranchManagerLoggedInEvent,
    PasswordChangedEvent,
    BranchManagerSuspendedEvent
};
use Modules\BranchManagers\Listeners\{
    SendWelcomeNotificationListener,
    LogLoginActivityListener,
    NotifyPasswordChangedListener,
    HandleSuspensionListener
};

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

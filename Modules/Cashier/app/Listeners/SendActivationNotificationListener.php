<?php

namespace Modules\Cashier\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Cashier\Events\CashierCreatedEvent;
use Modules\Cashier\Services\CashierActivationService;

class SendActivationNotificationListener
{
    public function __construct(
        private CashierActivationService $activationService
    ) {}

    public function handle(CashierCreatedEvent $event): void
    {
        $this->activationService->sendActivationLink(
            $event->cashier,
            $event->defaultPassword
        );

        Log::info('Activation notification sent', [
            'cashier_id' => $event->cashier->id,
            'email' => $event->cashier->email,
        ]);
    }
}

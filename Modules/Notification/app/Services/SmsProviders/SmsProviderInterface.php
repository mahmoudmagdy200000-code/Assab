<?php

namespace Modules\Notification\Services\SmsProviders;

interface SmsProviderInterface
{
    /**
     * Send SMS message
     */
    public function send(string $phoneNumber, string $message): bool;
}

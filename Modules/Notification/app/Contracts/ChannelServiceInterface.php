<?php

namespace Modules\Notification\Contracts;

use Modules\Notification\DataTransferObjects\NotificationEnvelope;
use Modules\Notification\Enums\NotificationChannel;

interface ChannelServiceInterface
{
    /**
     * Deliver an envelope over one channel.
     *
     * Implementations return false on a delivery failure and must not throw for
     * recipient-level problems (missing address, rate limit, dead token) —
     * callers rely on the boolean to write the delivery log.
     */
    public function send(NotificationEnvelope $envelope, NotificationChannel $channel): bool;
}

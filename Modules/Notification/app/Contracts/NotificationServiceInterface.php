<?php

namespace Modules\Notification\Contracts;

use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Enums\NotificationPriority;

interface NotificationServiceInterface
{
    /**
     * Send notification to a notifiable entity
     */
    public function send(
        object $notifiable,
        NotificationType $type,
        array $data,
        ?NotificationPriority $priority = null
    ): void;

    /**
     * Send notification to multiple notifiables
     */
    public function sendToMany(
        array $notifiables,
        NotificationType $type,
        array $data,
        ?NotificationPriority $priority = null
    ): void;

    /**
     * Send notification based on role
     */
    public function sendToRole(
        string $role,
        NotificationType $type,
        array $data,
        ?NotificationPriority $priority = null
    ): void;
}


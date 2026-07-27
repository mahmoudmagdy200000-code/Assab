<?php

namespace Modules\Notification\Contracts;

use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;

interface NotificationServiceInterface
{
    /**
     * Send a notification to one recipient across every channel their
     * preferences enable.
     *
     * @param  array<string, mixed>  $data
     */
    public function send(
        object $notifiable,
        NotificationType $type,
        array $data,
        ?NotificationPriority $priority = null
    ): void;

    /**
     * @param  array<int, object>  $notifiables
     * @param  array<string, mixed>  $data
     */
    public function sendToMany(
        array $notifiables,
        NotificationType $type,
        array $data,
        ?NotificationPriority $priority = null
    ): void;

    /**
     * Send to every active holder of a role.
     *
     * Roles are namespaced across the two worlds: `cashier`, `branch_manager`,
     * `brand_owner`, `supplier` address legacy logins; `asab:{role_key}`
     * addresses dashboard users.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendToRole(
        string $role,
        NotificationType $type,
        array $data,
        ?NotificationPriority $priority = null,
        ?string $branchId = null
    ): void;

    /**
     * Send to every active recipient attached to a branch, in both worlds.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendToBranch(
        string $branchId,
        NotificationType $type,
        array $data,
        ?NotificationPriority $priority = null
    ): void;

    /**
     * Fire-and-forget device broadcast to an FCM topic.
     *
     * Carries no per-recipient state: no in-app row, no preference gating. Use
     * for announcements every subscriber should see, never for anything
     * addressed to a person.
     *
     * @param  array<string, mixed>  $data
     */
    public function broadcastToTopic(
        string $topic,
        NotificationType $type,
        array $data,
        ?NotificationPriority $priority = null,
        ?string $locale = null
    ): void;
}

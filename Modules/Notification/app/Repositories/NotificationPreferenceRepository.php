<?php

namespace Modules\Notification\Repositories;

use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Models\NotificationPreference;

class NotificationPreferenceRepository implements NotificationPreferenceRepositoryInterface
{
    /**
     * Get preference for notifiable and type
     */
    public function getPreference(object $notifiable, NotificationType $type): ?NotificationPreference
    {
        return NotificationPreference::where('notifiable_type', get_class($notifiable))
            ->where('notifiable_id', $notifiable->id)
            ->where('notification_type', $type->value)
            ->first();
    }

    /**
     * Create or update preference
     */
    public function createOrUpdate(
        object $notifiable,
        NotificationType $type,
        array $channels,
        string $priorityLevel,
        bool $enabled
    ): NotificationPreference {
        return NotificationPreference::updateOrCreate(
            [
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id,
                'notification_type' => $type->value,
            ],
            [
                'channels' => $channels,
                'priority_level' => $priorityLevel,
                'enabled' => $enabled,
            ]
        );
    }

    /**
     * Get all preferences for notifiable
     */
    public function getAllPreferences(object $notifiable): array
    {
        return NotificationPreference::where('notifiable_type', get_class($notifiable))
            ->where('notifiable_id', $notifiable->id)
            ->get()
            ->keyBy('notification_type')
            ->toArray();
    }

    /**
     * Get default preferences for role
     */
    public function getDefaultPreferencesForRole(string $role): array
    {
        return match ($role) {
            'branch_manager' => $this->getBranchManagerDefaults(),
            'cashier' => $this->getCashierDefaults(),
            'supplier' => $this->getSupplierDefaults(),
            'brand_owner' => $this->getBrandOwnerDefaults(),
            default => [],
        };
    }

    /**
     * Default preferences for Branch Manager
     */
    private function getBranchManagerDefaults(): array
    {
        return [
            // Operational notifications - all enabled
            NotificationType::SHIFT_START_REMINDER->value => [
                'channels' => [NotificationChannel::IN_APP->value, NotificationChannel::EMAIL->value],
                'priority_level' => NotificationPriority::MEDIUM->value,
                'enabled' => true,
            ],
            NotificationType::SHIFT_HANDOVER_PENDING->value => [
                'channels' => [NotificationChannel::IN_APP->value],
                'priority_level' => NotificationPriority::HIGH->value,
                'enabled' => true,
            ],
            // Financial alerts - high priority only
            NotificationType::CASH_VARIANCE_HIGH->value => [
                'channels' => [NotificationChannel::IN_APP->value, NotificationChannel::EMAIL->value],
                'priority_level' => NotificationPriority::HIGH->value,
                'enabled' => true,
            ],
            // Email summaries
            NotificationType::EXPENSE_SUBMITTED->value => [
                'channels' => [NotificationChannel::IN_APP->value, NotificationChannel::EMAIL->value],
                'priority_level' => NotificationPriority::MEDIUM->value,
                'enabled' => true,
            ],
        ];
    }

    /**
     * Default preferences for Cashier
     */
    private function getCashierDefaults(): array
    {
        return [
            NotificationType::SHIFT_START_REMINDER->value => [
                'channels' => [NotificationChannel::IN_APP->value],
                'priority_level' => NotificationPriority::MEDIUM->value,
                'enabled' => true,
            ],
            NotificationType::SHIFT_HANDOVER_REQUEST->value => [
                'channels' => [NotificationChannel::IN_APP->value],
                'priority_level' => NotificationPriority::HIGH->value,
                'enabled' => true,
            ],
            NotificationType::CASH_VARIANCE_HIGH->value => [
                'channels' => [NotificationChannel::IN_APP->value],
                'priority_level' => NotificationPriority::HIGH->value,
                'enabled' => true,
            ],
        ];
    }

    /**
     * Default preferences for Supplier
     */
    private function getSupplierDefaults(): array
    {
        return [
            NotificationType::ORDER_CREATED->value => [
                'channels' => [NotificationChannel::IN_APP->value, NotificationChannel::EMAIL->value],
                'priority_level' => NotificationPriority::MEDIUM->value,
                'enabled' => true,
            ],
            NotificationType::ORDER_STATUS_CHANGED->value => [
                'channels' => [NotificationChannel::IN_APP->value],
                'priority_level' => NotificationPriority::MEDIUM->value,
                'enabled' => true,
            ],
        ];
    }

    /**
     * Default preferences for Brand Owner
     */
    private function getBrandOwnerDefaults(): array
    {
        return [
            NotificationType::EXPENSE_SUBMITTED->value => [
                'channels' => [NotificationChannel::IN_APP->value, NotificationChannel::EMAIL->value],
                'priority_level' => NotificationPriority::HIGH->value,
                'enabled' => true,
            ],
            NotificationType::COMPLIANCE_VIOLATION->value => [
                'channels' => [NotificationChannel::IN_APP->value, NotificationChannel::EMAIL->value, NotificationChannel::SMS->value],
                'priority_level' => NotificationPriority::CRITICAL->value,
                'enabled' => true,
            ],
        ];
    }
}


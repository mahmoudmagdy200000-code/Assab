<?php

namespace Modules\Notification\Repositories;

use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Models\NotificationPreference;

interface NotificationPreferenceRepositoryInterface
{
    /**
     * Get preference for notifiable and type
     */
    public function getPreference(object $notifiable, NotificationType $type): ?NotificationPreference;

    /**
     * Create or update preference
     */
    public function createOrUpdate(
        object $notifiable,
        NotificationType $type,
        array $channels,
        string $priorityLevel,
        bool $enabled
    ): NotificationPreference;

    /**
     * Get all preferences for notifiable
     */
    public function getAllPreferences(object $notifiable): array;

    /**
     * Get default preferences for role
     */
    public function getDefaultPreferencesForRole(string $role): array;
}


<?php

namespace Modules\Notification\Repositories;

use Illuminate\Notifications\Notifiable;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Models\NotificationPreference;

interface NotificationPreferenceRepositoryInterface
{
    /**
     * Get preference for notifiable and type
     */
    public function getPreference(Notifiable $notifiable, NotificationType $type): ?NotificationPreference;

    /**
     * Create or update preference
     */
    public function createOrUpdate(
        Notifiable $notifiable,
        NotificationType $type,
        array $channels,
        string $priorityLevel,
        bool $enabled
    ): NotificationPreference;

    /**
     * Get all preferences for notifiable
     */
    public function getAllPreferences(Notifiable $notifiable): array;

    /**
     * Get default preferences for role
     */
    public function getDefaultPreferencesForRole(string $role): array;
}


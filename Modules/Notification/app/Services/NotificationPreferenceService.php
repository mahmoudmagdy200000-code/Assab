<?php

namespace Modules\Notification\Services;

use Illuminate\Notifications\Notifiable;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Repositories\NotificationPreferenceRepositoryInterface;

class NotificationPreferenceService
{
    public function __construct(
        private NotificationPreferenceRepositoryInterface $repository
    ) {}

    /**
     * Initialize default preferences for a user based on role
     */
    public function initializeDefaults(Notifiable $notifiable, string $role): void
    {
        $defaults = $this->repository->getDefaultPreferencesForRole($role);

        foreach ($defaults as $typeValue => $preference) {
            $type = NotificationType::from($typeValue);
            $this->repository->createOrUpdate(
                $notifiable,
                $type,
                $preference['channels'],
                $preference['priority_level'],
                $preference['enabled']
            );
        }
    }

    /**
     * Update user preference
     */
    public function updatePreference(
        Notifiable $notifiable,
        NotificationType $type,
        array $channels,
        string $priorityLevel,
        bool $enabled
    ): void {
        $this->repository->createOrUpdate(
            $notifiable,
            $type,
            $channels,
            $priorityLevel,
            $enabled
        );
    }

    /**
     * Get all preferences for user
     */
    public function getUserPreferences(Notifiable $notifiable): array
    {
        return $this->repository->getAllPreferences($notifiable);
    }
}

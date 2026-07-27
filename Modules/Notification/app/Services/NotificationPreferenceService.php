<?php

namespace Modules\Notification\Services;

use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Repositories\NotificationPreferenceRepositoryInterface;

/**
 * Notification preference management.
 *
 * Recipients are typed `object`, not `Notifiable`: the latter is a *trait*, and
 * a trait used in a parameter type declaration can never be satisfied — every
 * call through this service raised a TypeError before reaching the repository.
 */
class NotificationPreferenceService
{
    public function __construct(
        private readonly NotificationPreferenceRepositoryInterface $repository
    ) {}

    /**
     * Seed a new user's preferences from their role defaults.
     */
    public function initializeDefaults(object $notifiable, string $role): void
    {
        $defaults = $this->repository->getDefaultPreferencesForRole($role);

        foreach ($defaults as $typeValue => $preference) {
            $type = NotificationType::tryFrom($typeValue);

            // A defaults table that outlived a renamed type must not take the
            // whole seeding run down with it.
            if ($type === null) {
                continue;
            }

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
     * @param  array<int, string>  $channels
     */
    public function updatePreference(
        object $notifiable,
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
     * @return array<string, mixed>
     */
    public function getUserPreferences(object $notifiable): array
    {
        return $this->repository->getAllPreferences($notifiable);
    }
}

<?php

namespace Modules\Notification\Services;

use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Contracts\ChannelServiceInterface;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Models\NotificationPreference;
use Modules\Notification\Models\NotificationLog;
use Modules\Notification\Repositories\NotificationPreferenceRepositoryInterface;

class NotificationService implements NotificationServiceInterface
{
    public function __construct(
        private ChannelServiceInterface $channelService,
        private NotificationPreferenceRepositoryInterface $preferenceRepository
    ) {}

    /**
     * Send notification to a notifiable entity
     */
    public function send(
        Notifiable $notifiable,
        NotificationType $type,
        array $data,
        NotificationPriority $priority = null
    ): void {
        $priority = $priority ?? $type->defaultPriority();

        // Check user preferences
        $preference = $this->preferenceRepository->getPreference($notifiable, $type);

        if (!$preference || !$preference->shouldReceive($priority)) {
            return;
        }

        // Get enabled channels
        $channels = $preference->channels ?? [NotificationChannel::IN_APP->value];

        // Send via each enabled channel
        foreach ($channels as $channelValue) {
            $channel = NotificationChannel::from($channelValue);
            $this->sendViaChannel($notifiable, $channel, $type, $data, $priority);
        }
    }

    /**
     * Send notification to multiple notifiables
     */
    public function sendToMany(
        array $notifiables,
        NotificationType $type,
        array $data,
        NotificationPriority $priority = null
    ): void {
        foreach ($notifiables as $notifiable) {
            try {
                $this->send($notifiable, $type, $data, $priority);
            } catch (\Exception $e) {
                Log::error('Failed to send notification', [
                    'notifiable_id' => $notifiable->id ?? null,
                    'type' => $type->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send notification based on role
     */
    public function sendToRole(
        string $role,
        NotificationType $type,
        array $data,
        NotificationPriority $priority = null
    ): void {
        // This will be implemented based on your role system
        // For now, placeholder - you'll need to adapt to your role management
        Log::info('Role-based notification', [
            'role' => $role,
            'type' => $type->value,
        ]);
    }

    /**
     * Send notification via specific channel
     */
    private function sendViaChannel(
        Notifiable $notifiable,
        NotificationChannel $channel,
        NotificationType $type,
        array $data,
        NotificationPriority $priority
    ): void {
        try {
            $title = $this->generateTitle($type, $data);
            $message = $this->generateMessage($type, $data);

            // Create in-app notification first
            if ($channel === NotificationChannel::IN_APP) {
                $notification = $notifiable->notify(
                    new \Modules\Notification\Notifications\BaseNotification($type, $data, $priority)
                );

                // Log notification - get ID from database notification
                $dbNotification = $notifiable->notifications()->latest()->first();
                if ($dbNotification) {
                    $this->logNotification($dbNotification->id, $channel, 'sent');
                }
            } else {
                // Send via external channel
                $success = $this->channelService->send($notifiable, $channel, $title, $message, $data);

                // Also create in-app notification for tracking
                $dbNotification = $notifiable->notify(
                    new \Modules\Notification\Notifications\BaseNotification($type, $data, $priority)
                );

                $dbNotification = $notifiable->notifications()->latest()->first();
                if ($dbNotification) {
                    $this->logNotification($dbNotification->id, $channel, $success ? 'sent' : 'failed');
                }
            }
        } catch (\Exception $e) {
            Log::error('Channel notification failed', [
                'channel' => $channel->value,
                'type' => $type->value,
                'error' => $e->getMessage(),
            ]);

            if (isset($notification)) {
                $this->logNotification($notification->id ?? null, $channel, 'failed', $e->getMessage());
            }
        }
    }

    /**
     * Generate notification title
     */
    private function generateTitle(NotificationType $type, array $data): string
    {
        $title = $type->label();

        // Customize based on type and data
        return mb_substr($title, 0, 60);
    }

    /**
     * Generate notification message
     */
    private function generateMessage(NotificationType $type, array $data): string
    {
        // Generate message based on type and data
        $message = match ($type) {
            NotificationType::SHIFT_START_REMINDER => "Your shift starts in 15 minutes",
            NotificationType::SHIFT_HANDOVER_APPROVED => "Handover has been approved",
            NotificationType::EXPENSE_APPROVED => "Your expense has been approved",
            default => $type->label(),
        };

        return mb_substr($message, 0, 120);
    }

    /**
     * Log notification delivery
     */
    private function logNotification(
        ?string $notificationId,
        NotificationChannel $channel,
        string $status,
        ?string $errorMessage = null
    ): void {
        if (!$notificationId) {
            return;
        }

        NotificationLog::create([
            'notification_id' => $notificationId,
            'channel' => $channel->value,
            'status' => $status,
            'error_message' => $errorMessage,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}


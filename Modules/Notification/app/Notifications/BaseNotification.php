<?php

namespace Modules\Notification\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Notification\DataTransferObjects\FcmMessage;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Events\NotificationBroadcasted;
use Modules\Notification\Services\Fcm\FcmMessageFactory;
use Modules\Notification\Services\NotificationCopyResolver;

/**
 * Laravel notification wrapper around a NotificationType.
 *
 * Two ways in:
 *  - NotificationService::send() — preferences, delivery logs, identity
 *    mirroring. This class then only names the row's `type` column.
 *  - $user->notify(new BaseNotification(...)) — direct, for callers that want
 *    the plain Laravel path. `via()` then includes `fcm` when the push channel
 *    is requested.
 *
 * Copy is resolved through NotificationCopyResolver so every channel and both
 * entry points render the same localized strings.
 */
class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var array<int, string> */
    protected array $channels;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $channels
     */
    public function __construct(
        public NotificationType $type,
        public array $data = [],
        public ?NotificationPriority $priority = null,
        array $channels = []
    ) {
        $this->priority = $priority ?? $this->type->defaultPriority();
        $this->channels = $channels;
    }

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        $via = ['database'];

        if ($this->wantsPush() && method_exists($notifiable, 'deviceTokens')) {
            $via[] = 'fcm';
        }

        return $via;
    }

    public function toFcm($notifiable): FcmMessage
    {
        return $this->fcmFactory()->make(
            $this->type,
            $this->data,
            $this->priority,
            $this->localeFor($notifiable),
            $this->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        $locale = $this->localeFor($notifiable);

        return [
            'type' => $this->type->value,
            'title' => $this->copy()->title($this->type, $this->data, $locale),
            'message' => $this->copy()->body($this->type, $this->data, $locale),
            'priority' => $this->priority->value,
            'category' => $this->type->category()->value,
            'data' => $this->data,
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Pusher broadcast for an open client. Retained for callers that drive the
     * notification directly; NotificationService broadcasts on its own.
     */
    public function broadcastTo($notifiable): void
    {
        if (! $this->wantsPush()) {
            return;
        }

        $locale = $this->localeFor($notifiable);

        event(new NotificationBroadcasted($notifiable, [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->copy()->title($this->type, $this->data, $locale),
            'message' => $this->copy()->body($this->type, $this->data, $locale),
            'priority' => $this->priority->value,
            'category' => $this->type->category()->value,
            'data' => $this->data,
            'created_at' => now()->toIso8601String(),
        ]));
    }

    private function wantsPush(): bool
    {
        return in_array(NotificationChannel::PUSH->value, $this->channels, true);
    }

    private function localeFor(object $notifiable): string
    {
        return $this->copy()->localeFor($notifiable);
    }

    /**
     * Resolved lazily rather than injected: notifications are serialised onto
     * the queue, and a constructor-injected service would be serialised with them.
     */
    private function copy(): NotificationCopyResolver
    {
        return app(NotificationCopyResolver::class);
    }

    private function fcmFactory(): FcmMessageFactory
    {
        return app(FcmMessageFactory::class);
    }
}

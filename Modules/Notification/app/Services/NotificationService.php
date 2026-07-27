<?php

namespace Modules\Notification\Services;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Str;
use Modules\Notification\Contracts\ChannelServiceInterface;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\DataTransferObjects\NotificationEnvelope;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Events\NotificationBroadcasted;
use Modules\Notification\Jobs\SendFcmMessageJob;
use Modules\Notification\Jobs\SendFcmTopicMessageJob;
use Modules\Notification\Notifications\BaseNotification;
use Modules\Notification\Repositories\AudienceRepositoryInterface;
use Modules\Notification\Repositories\NotificationPreferenceRepositoryInterface;
use Modules\Notification\Repositories\NotificationRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates notification delivery. Holds no queries and no formatting of its
 * own: preferences and audiences come from repositories, copy from the resolver,
 * transport from the channel service.
 */
class NotificationService implements NotificationServiceInterface
{
    /**
     * Recipients addressed per role/branch broadcast before the fan-out is cut
     * off. Past this, a topic broadcast is the right tool — a per-recipient
     * fan-out of that size would be tens of thousands of queued jobs.
     */
    private const MAX_FANOUT_RECIPIENTS = 5000;

    public function __construct(
        private readonly ChannelServiceInterface $channelService,
        private readonly NotificationPreferenceRepositoryInterface $preferenceRepository,
        private readonly NotificationRepositoryInterface $notificationRepository,
        private readonly AudienceRepositoryInterface $audienceRepository,
        private readonly NotificationCopyResolver $copyResolver,
        private readonly EventDispatcher $events,
        private readonly BusDispatcher $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(
        object $notifiable,
        NotificationType $type,
        array $data,
        ?NotificationPriority $priority = null
    ): void {
        if (! $this->isNotifiable($notifiable)) {
            throw new \InvalidArgumentException('Provided entity is not notifiable.');
        }

        $priority ??= $type->defaultPriority();
        $channels = $this->resolveChannels($notifiable, $type, $priority);

        if ($channels === []) {
            return;
        }

        $locale = $this->copyResolver->localeFor($notifiable);
        $notificationId = (string) Str::uuid();

        $envelope = new NotificationEnvelope(
            notifiable: $notifiable,
            type: $type,
            data: $data,
            priority: $priority,
            title: $this->copyResolver->title($type, $data, $locale),
            message: $this->copyResolver->body($type, $data, $locale),
            notificationId: $notificationId,
        );

        // The in-app row is written first and with a known id, so push payloads
        // and delivery logs can reference it. The previous implementation read
        // it back with `notifications()->latest()->first()`, which races with
        // any concurrent notification to the same user.
        $this->recordInApp($envelope);

        foreach ($channels as $channel) {
            if ($channel === NotificationChannel::IN_APP) {
                continue;
            }

            $accepted = $this->channelService->send($envelope, $channel);

            // Push is asynchronous: the queued job knows the real per-device
            // outcome and writes its own log row. Logging "sent" here as well
            // would produce two rows, one of them a lie.
            if ($channel !== NotificationChannel::PUSH) {
                $this->notificationRepository->logDelivery(
                    $notificationId,
                    $channel,
                    $accepted ? 'sent' : 'failed'
                );
            }
        }

        $this->broadcast($envelope);
        $this->mirrorToLinkedIdentities($envelope);
    }

    public function sendToMany(
        array $notifiables,
        NotificationType $type,
        array $data,
        ?NotificationPriority $priority = null
    ): void {
        foreach ($notifiables as $notifiable) {
            $this->sendGuarded($notifiable, $type, $data, $priority);
        }
    }

    public function sendToRole(
        string $role,
        NotificationType $type,
        array $data,
        ?NotificationPriority $priority = null,
        ?string $branchId = null
    ): void {
        $sent = 0;

        foreach ($this->audienceRepository->withRole($role, $branchId) as $recipient) {
            if (++$sent > self::MAX_FANOUT_RECIPIENTS) {
                $this->logger->warning('Role fan-out truncated', [
                    'role' => $role,
                    'type' => $type->value,
                    'limit' => self::MAX_FANOUT_RECIPIENTS,
                ]);
                break;
            }

            $this->sendGuarded($recipient, $type, $data, $priority);
        }
    }

    public function sendToBranch(
        string $branchId,
        NotificationType $type,
        array $data,
        ?NotificationPriority $priority = null
    ): void {
        $sent = 0;

        foreach ($this->audienceRepository->inBranch($branchId) as $recipient) {
            if (++$sent > self::MAX_FANOUT_RECIPIENTS) {
                $this->logger->warning('Branch fan-out truncated', [
                    'branch_id' => $branchId,
                    'type' => $type->value,
                    'limit' => self::MAX_FANOUT_RECIPIENTS,
                ]);
                break;
            }

            $this->sendGuarded($recipient, $type, $data, $priority);
        }
    }

    public function broadcastToTopic(
        string $topic,
        NotificationType $type,
        array $data,
        ?NotificationPriority $priority = null,
        ?string $locale = null
    ): void {
        $this->bus->dispatch(new SendFcmTopicMessageJob(
            topic: $topic,
            type: $type,
            data: $data,
            priority: $priority ?? $type->defaultPriority(),
            locale: $locale ?? (string) config('app.locale', 'en'),
        ));
    }

    /**
     * One recipient's failure must not abort a fan-out.
     *
     * @param  array<string, mixed>  $data
     */
    private function sendGuarded(
        object $notifiable,
        NotificationType $type,
        array $data,
        ?NotificationPriority $priority
    ): void {
        try {
            $this->send($notifiable, $type, $data, $priority);
        } catch (\InvalidArgumentException|\Illuminate\Database\QueryException $e) {
            $this->logger->error('Failed to send notification', [
                'notifiable_type' => $notifiable::class,
                'type' => $type->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Channels this recipient should receive this type on, honouring their
     * preference row when one exists.
     *
     * With no stored preference the configured defaults apply. The previous
     * implementation returned early when no row existed, which silently dropped
     * every notification for every user who had never opened the preferences
     * screen — i.e. almost all of them.
     *
     * @return array<int, NotificationChannel>
     */
    private function resolveChannels(
        object $notifiable,
        NotificationType $type,
        NotificationPriority $priority
    ): array {
        $preference = $this->preferenceRepository->getPreference($notifiable, $type);

        if ($preference !== null && ! $type->isMandatory()) {
            if (! $preference->shouldReceive($priority)) {
                return [];
            }

            $values = $preference->channels ?: [];
        } elseif ($preference !== null) {
            // Mandatory type: honour the recipient's channel choice but ignore
            // the enabled/priority gate. Compliance and account-security events
            // are not opt-out.
            $values = $preference->channels ?: (array) config('notification.defaults.channels', []);
        } else {
            $values = (array) config('notification.defaults.channels', [
                NotificationChannel::IN_APP->value,
                NotificationChannel::PUSH->value,
            ]);
        }

        $channels = [];

        foreach ($values as $value) {
            $channel = NotificationChannel::tryFrom((string) $value);

            if ($channel === null) {
                continue;
            }

            if ($channel === NotificationChannel::PUSH && ! $this->pushAddressable($notifiable)) {
                continue;
            }

            $channels[] = $channel;
        }

        // Never let a stale or malformed preference silence a critical alert.
        if ($priority === NotificationPriority::CRITICAL && ! in_array(NotificationChannel::IN_APP, $channels, true)) {
            array_unshift($channels, NotificationChannel::IN_APP);
        }

        return array_values(array_unique($channels, SORT_REGULAR));
    }

    private function recordInApp(NotificationEnvelope $envelope): void
    {
        $payload = [
            'type' => $envelope->type->value,
            'title' => $envelope->title,
            'message' => $envelope->message,
            'priority' => $envelope->priority->value,
            'category' => $envelope->type->category()->value,
            'data' => $envelope->data,
            'created_at' => now()->toIso8601String(),
        ];

        $this->notificationRepository->record(
            $envelope->notifiable,
            $envelope->notificationId,
            BaseNotification::class,
            $payload
        );

        $this->notificationRepository->logDelivery(
            $envelope->notificationId,
            NotificationChannel::IN_APP,
            'sent'
        );
    }

    /**
     * Pusher broadcast for clients that are open right now. Independent of FCM:
     * Pusher updates a live UI, FCM wakes a backgrounded app.
     */
    private function broadcast(NotificationEnvelope $envelope): void
    {
        $this->events->dispatch(new NotificationBroadcasted($envelope->notifiable, [
            'id' => $envelope->notificationId,
            'type' => $envelope->type->value,
            'title' => $envelope->title,
            'message' => $envelope->message,
            'priority' => $envelope->priority->value,
            'category' => $envelope->type->category()->value,
            'data' => $envelope->data,
            'created_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * The same human often holds a dashboard account and a legacy mobile
     * account. Push (only push — no duplicate in-app rows) is mirrored to the
     * linked account so the alert reaches whichever app they have installed.
     */
    private function mirrorToLinkedIdentities(NotificationEnvelope $envelope): void
    {
        if (! config('notification.fcm.mirror_linked_identities', true)) {
            return;
        }

        foreach ($this->audienceRepository->linkedIdentities($envelope->notifiable) as $linked) {
            if (! $this->pushAddressable($linked)) {
                continue;
            }

            $this->bus->dispatch(new SendFcmMessageJob(
                notifiableType: $linked->getMorphClass(),
                notifiableId: (string) $linked->getKey(),
                type: $envelope->type,
                data: $envelope->data,
                priority: $envelope->priority,
                notificationId: null,
            ));
        }
    }

    private function pushAddressable(object $notifiable): bool
    {
        return method_exists($notifiable, 'deviceTokens');
    }

    private function isNotifiable(object $entity): bool
    {
        return method_exists($entity, 'notify') && method_exists($entity, 'notifications');
    }
}

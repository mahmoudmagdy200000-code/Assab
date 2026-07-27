<?php

namespace Modules\Notification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Notification\Contracts\FcmClientInterface;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Services\Fcm\FcmMessageFactory;
use Psr\Log\LoggerInterface;

/**
 * Broadcasts one message to an FCM topic (all cashiers, a branch, a company).
 *
 * Topic sends carry no per-recipient state, so there is no in-app record and no
 * preference gating — use this only for announcements every subscriber should
 * see. Anything addressed to a person goes through SendFcmMessageJob.
 */
class SendFcmTopicMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 180];

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $topic,
        public readonly NotificationType $type,
        public readonly array $data,
        public readonly NotificationPriority $priority,
        public readonly string $locale = 'en',
    ) {
        $this->onQueue(config('notification.fcm.queue', 'notifications'));
    }

    public function handle(
        FcmClientInterface $client,
        FcmMessageFactory $messageFactory,
        LoggerInterface $logger,
    ): void {
        $result = $client->sendToTopic(
            $this->topic,
            $messageFactory->make($this->type, $this->data, $this->priority, $this->locale)
        );

        if ($result->success) {
            return;
        }

        $logger->warning('FCM topic broadcast failed', [
            'topic' => $this->topic,
            'type' => $this->type->value,
            'error' => $result->errorCode.': '.$result->errorMessage,
        ]);

        if ($result->retryable && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 180);
        }
    }
}

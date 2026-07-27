<?php

namespace Modules\Notification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Notification\Contracts\FcmClientInterface;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Models\NotificationLog;
use Modules\Notification\Repositories\DeviceTokenRepositoryInterface;
use Modules\Notification\Services\Fcm\FcmMessageFactory;
use Psr\Log\LoggerInterface;

/**
 * Delivers one notification to every device belonging to one owner.
 *
 * Runs on the queue because FCM is a synchronous third-party HTTP call and a
 * user with five devices would otherwise add five round-trips to the request
 * that triggered the notification. The job carries identifiers rather than the
 * model so a deleted owner degrades to a no-op instead of a
 * ModelNotFoundException storm.
 */
class SendFcmMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Exponential-ish backoff; FCM 5xx/429 typically clears inside a minute. */
    public array $backoff = [10, 60, 180];

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $notifiableType,
        public readonly string $notifiableId,
        public readonly NotificationType $type,
        public readonly array $data,
        public readonly NotificationPriority $priority,
        public readonly ?string $notificationId = null,
    ) {
        $this->onQueue(config('notification.fcm.queue', 'notifications'));
    }

    public function handle(
        FcmClientInterface $client,
        DeviceTokenRepositoryInterface $repository,
        FcmMessageFactory $messageFactory,
        LoggerInterface $logger,
    ): void {
        $owner = $this->resolveOwner();

        if ($owner === null) {
            return;
        }

        $devices = $repository->forOwner($owner);

        if ($devices->isEmpty()) {
            return;
        }

        $prune = [];
        $delivered = [];
        $retryable = false;
        $lastError = null;

        // One rendered message per locale, not per device: a user with three
        // Arabic handsets renders once.
        $messages = [];

        foreach ($devices as $device) {
            $locale = $device->locale ?: config('app.locale', 'en');

            $messages[$locale] ??= $messageFactory->make(
                $this->type,
                $this->data,
                $this->priority,
                $locale,
                $this->notificationId,
            );

            $result = $client->sendToToken($device->token, $messages[$locale]);

            if ($result->success) {
                $delivered[] = $device->token;

                continue;
            }

            if ($result->shouldPrune) {
                $prune[] = $device->token;

                continue;
            }

            $retryable = $retryable || $result->retryable;
            $lastError = $result->errorCode.': '.$result->errorMessage;
        }

        if ($prune !== []) {
            $repository->forgetTokens($prune);
        }

        if ($delivered !== []) {
            $repository->markUsed($delivered);
            $this->log('sent');

            return;
        }

        // Every device failed for a transient reason — let the queue retry the
        // whole owner rather than swallowing a Firebase outage. No log row yet:
        // the attempt is not a final outcome.
        if ($retryable && $this->attempts() < $this->tries) {
            $logger->warning('FCM delivery failed, retrying', [
                'notifiable_type' => $this->notifiableType,
                'type' => $this->type->value,
                'attempt' => $this->attempts(),
                'error' => $lastError,
            ]);

            $this->release($this->backoff[$this->attempts() - 1] ?? 180);

            return;
        }

        // Nothing left to try, or every token was pruned as dead.
        $this->log('failed', $lastError ?? 'all device tokens were rejected');
    }

    public function failed(\Throwable $exception): void
    {
        $this->log('failed', $exception->getMessage());
    }

    private function resolveOwner(): ?object
    {
        // notifiableType is whatever getMorphClass() returned, which for the
        // models in Shift's morph map ('cashier', 'branch_manager') is an alias,
        // not a class name. Resolve through the map before touching the class.
        $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($this->notifiableType)
            ?? $this->notifiableType;

        if (! class_exists($class) || ! is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
            return null;
        }

        return $class::query()->find($this->notifiableId);
    }

    private function log(string $status, ?string $error = null): void
    {
        if ($this->notificationId === null) {
            return;
        }

        NotificationLog::create([
            'notification_id' => $this->notificationId,
            'channel' => NotificationChannel::PUSH->value,
            'status' => $status,
            'error_message' => $error !== null ? mb_substr($error, 0, 500) : null,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}

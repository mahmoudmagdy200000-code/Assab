<?php

namespace Modules\Notification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Notification\Services\FcmTopicService;

/**
 * Applies FCM topic membership for one device off the request path.
 *
 * Topic changes are one Instance-ID round-trip per topic, and a device belongs
 * to about five. Doing that inline would add five sequential HTTP calls to a
 * registration the mobile app performs on every launch.
 */
class SyncDeviceTopicsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    /**
     * @param  array<int, string>  $topics
     */
    public function __construct(
        public readonly string $token,
        public readonly array $topics,
        public readonly bool $subscribe = true,
    ) {
        $this->onQueue(config('notification.fcm.queue', 'notifications'));
    }

    public function handle(FcmTopicService $topicService): void
    {
        if ($this->topics === []) {
            return;
        }

        $this->subscribe
            ? $topicService->attach($this->token, $this->topics)
            : $topicService->detach($this->token, $this->topics);
    }
}

<?php

namespace Tests\Fakes;

use Modules\Notification\Contracts\FcmClientInterface;
use Modules\Notification\DataTransferObjects\FcmMessage;
use Modules\Notification\DataTransferObjects\FcmSendResult;

/**
 * Test double for the FCM transport: records what would have been sent and lets
 * a test script per-token outcomes (delivered / prunable / transient).
 */
class RecordingFcmClient implements FcmClientInterface
{
    /** @var array<int, array{token: string, message: FcmMessage}> */
    public array $sent = [];

    /** @var array<int, array{topic: string, message: FcmMessage}> */
    public array $topicSends = [];

    /** @var array<int, array{topic: string, tokens: array<int, string>}> */
    public array $subscriptions = [];

    /** @var array<int, array{topic: string, tokens: array<int, string>}> */
    public array $unsubscriptions = [];

    /** @var array<string, string> token => 'prune'|'transient'|'permanent' */
    public array $failures = [];

    public function sendToToken(string $token, FcmMessage $message): FcmSendResult
    {
        $this->sent[] = ['token' => $token, 'message' => $message];

        return match ($this->failures[$token] ?? null) {
            'prune' => FcmSendResult::invalidToken('UNREGISTERED'),
            'transient' => FcmSendResult::transientFailure('UNAVAILABLE'),
            'permanent' => FcmSendResult::permanentFailure('INTERNAL'),
            default => FcmSendResult::success('projects/test/messages/1'),
        };
    }

    public function sendToTopic(string $topic, FcmMessage $message): FcmSendResult
    {
        $this->topicSends[] = ['topic' => $topic, 'message' => $message];

        return FcmSendResult::success('projects/test/messages/topic');
    }

    public function subscribeToTopic(array $tokens, string $topic): array
    {
        $this->subscriptions[] = ['topic' => $topic, 'tokens' => $tokens];

        return [];
    }

    public function unsubscribeFromTopic(array $tokens, string $topic): array
    {
        $this->unsubscriptions[] = ['topic' => $topic, 'tokens' => $tokens];

        return [];
    }

    /**
     * @return array<int, string>
     */
    public function sentTokens(): array
    {
        return array_column($this->sent, 'token');
    }

    /**
     * @return array<int, string>
     */
    public function topics(): array
    {
        return array_values(array_unique(array_column($this->subscriptions, 'topic')));
    }
}

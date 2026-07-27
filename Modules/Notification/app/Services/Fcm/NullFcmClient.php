<?php

namespace Modules\Notification\Services\Fcm;

use Modules\Notification\Contracts\FcmClientInterface;
use Modules\Notification\DataTransferObjects\FcmMessage;
use Modules\Notification\DataTransferObjects\FcmSendResult;
use Psr\Log\LoggerInterface;

/**
 * No-op transport used when no Firebase service account is configured (local,
 * CI, and any environment where FCM_DRIVER=null).
 *
 * It reports success so the surrounding delivery pipeline — preferences,
 * logging, in-app records — stays exercisable without network access. Tokens are
 * masked in the log line: a registration token is a device credential and grants
 * anyone holding it the ability to push to that device.
 */
class NullFcmClient implements FcmClientInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $logMessages = true,
    ) {}

    public function sendToToken(string $token, FcmMessage $message): FcmSendResult
    {
        $this->record('token', $this->mask($token), $message);

        return FcmSendResult::success('null-driver');
    }

    public function sendToTopic(string $topic, FcmMessage $message): FcmSendResult
    {
        $this->record('topic', $topic, $message);

        return FcmSendResult::success('null-driver');
    }

    public function subscribeToTopic(array $tokens, string $topic): array
    {
        return [];
    }

    public function unsubscribeFromTopic(array $tokens, string $topic): array
    {
        return [];
    }

    private function record(string $targetType, string $target, FcmMessage $message): void
    {
        if (! $this->logMessages) {
            return;
        }

        $this->logger->debug('FCM (null driver) push suppressed', [
            'target_type' => $targetType,
            'target' => $target,
            'title' => $message->title,
            'priority' => $message->priority->value,
            'type' => $message->data['type'] ?? null,
        ]);
    }

    private function mask(string $token): string
    {
        return mb_strlen($token) <= 12
            ? str_repeat('*', mb_strlen($token))
            : mb_substr($token, 0, 6).'…'.mb_substr($token, -4);
    }
}

<?php

namespace Modules\Notification\Contracts;

use Modules\Notification\DataTransferObjects\FcmMessage;
use Modules\Notification\DataTransferObjects\FcmSendResult;

/**
 * Push transport abstraction (DIP). Implementations must never throw for a
 * rejected token — a rejection is data, returned as an FcmSendResult, so the
 * caller can prune. Only unrecoverable configuration faults may throw.
 */
interface FcmClientInterface
{
    /**
     * Deliver one message to one registration token.
     */
    public function sendToToken(string $token, FcmMessage $message): FcmSendResult;

    /**
     * Deliver one message to a topic (role/branch/company broadcast).
     */
    public function sendToTopic(string $topic, FcmMessage $message): FcmSendResult;

    /**
     * Subscribe registration tokens to a topic.
     *
     * @param  array<int, string>  $tokens
     * @return array<int, string> tokens FCM rejected and that should be pruned
     */
    public function subscribeToTopic(array $tokens, string $topic): array;

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, string> tokens FCM rejected and that should be pruned
     */
    public function unsubscribeFromTopic(array $tokens, string $topic): array;
}

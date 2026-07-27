<?php

namespace Modules\Notification\DataTransferObjects;

/**
 * Outcome of a single token send. `shouldPrune` is the only signal callers need
 * to keep the device_tokens table clean; `retryable` distinguishes a transient
 * FCM outage from a permanent rejection so the job does not burn retries.
 */
final class FcmSendResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $messageId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $shouldPrune = false,
        public readonly bool $retryable = false,
    ) {}

    public static function success(?string $messageId = null): self
    {
        return new self(success: true, messageId: $messageId);
    }

    public static function invalidToken(string $errorCode, ?string $message = null): self
    {
        return new self(
            success: false,
            errorCode: $errorCode,
            errorMessage: $message,
            shouldPrune: true,
        );
    }

    public static function transientFailure(string $errorCode, ?string $message = null): self
    {
        return new self(
            success: false,
            errorCode: $errorCode,
            errorMessage: $message,
            retryable: true,
        );
    }

    public static function permanentFailure(string $errorCode, ?string $message = null): self
    {
        return new self(
            success: false,
            errorCode: $errorCode,
            errorMessage: $message,
        );
    }
}

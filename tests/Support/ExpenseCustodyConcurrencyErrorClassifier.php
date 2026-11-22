<?php

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final class ExpenseCustodyConcurrencyErrorClassifier
{
    public static function isExpectedConflict(Throwable $exception, string $command): bool
    {
        if ($exception instanceof ConflictHttpException) {
            return true;
        }

        if (in_array($command, ['approve', 'reject'], true)) {
            return get_class($exception) === \Exception::class
                && (str_starts_with($exception->getMessage(), 'Only pending expenses can be ')
                    || str_starts_with($exception->getMessage(), 'This expense is already in the accounting review cycle'));
        }

        if ($command === 'confirm_receipt') {
            return $exception instanceof RuntimeException
                && in_array($exception->getMessage(), [
                    'This custody request was already received.',
                    'This custody request is not awaiting receipt.',
                    'A financial posting already exists for this custody request.',
                ], true);
        }

        return false;
    }

    public static function sanitizeErrorMessage(Throwable $exception): string
    {
        $sanitized = (string) preg_replace(
            '/(password|token|secret)=[^\s;&]+/i',
            '$1=[REDACTED]',
            $exception->getMessage()
        );

        return get_class($exception).': '.$sanitized;
    }
}

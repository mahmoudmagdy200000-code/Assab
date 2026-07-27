<?php

namespace Modules\Notification\Exceptions;

use RuntimeException;

/**
 * The FCM transport is misconfigured (missing/unreadable service-account file,
 * malformed JSON, absent project id). Distinct from a delivery failure: this is
 * an operator fault and must surface loudly rather than be retried.
 */
class FcmConfigurationException extends RuntimeException
{
    public static function missingCredentials(?string $path): self
    {
        return new self(sprintf(
            'FCM service-account credentials not readable at [%s]. Set FIREBASE_CREDENTIALS to the JSON key path, or set FCM_DRIVER=null to disable push delivery.',
            $path ?: '(unset)'
        ));
    }

    public static function invalidCredentials(string $reason): self
    {
        return new self('FCM service-account credentials are invalid: '.$reason);
    }

    public static function missingProjectId(): self
    {
        return new self('FCM project id is not configured. Set FIREBASE_PROJECT_ID or include project_id in the service-account JSON.');
    }

    public static function tokenExchangeFailed(string $reason): self
    {
        return new self('Failed to exchange the FCM service-account assertion for an access token: '.$reason);
    }
}

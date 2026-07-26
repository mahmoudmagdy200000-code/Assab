<?php

namespace App\Exceptions;

/**
 * A mobile account that still has to complete «activate Account» tried the
 * normal login, while FirstLoginPolicy forces the reset.
 *
 * `getStatusCode()` is what keeps this a 403 instead of a 500: the mobile
 * controllers funnel exceptions through App\ApiResponse::handleException /
 * BaseController::handleException, which read this method.
 */
class FirstLoginRequiredException extends \RuntimeException
{
    public function __construct(string $message = 'Please complete the account activation first.')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return 403;
    }
}

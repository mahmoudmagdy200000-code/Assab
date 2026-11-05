<?php

namespace Modules\BranchManagers\Exceptions;

use Exception;

class BranchManagerAuthException extends Exception
{
    public function __construct(string $message = 'Invalid credentials', public readonly string $reason = 'no_user')
    {
        parent::__construct($message);
    }
}

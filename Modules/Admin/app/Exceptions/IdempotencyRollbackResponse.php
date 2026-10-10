<?php

namespace Modules\Admin\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Throwable;

class IdempotencyRollbackResponse extends \RuntimeException
{
    public function __construct(public readonly Response $response, ?Throwable $previous = null)
    {
        parent::__construct('Rollback request transaction after a non-success response.', 0, $previous);
    }
}

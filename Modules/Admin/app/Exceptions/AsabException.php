<?php

namespace Modules\Admin\Exceptions;

/**
 * Domain exception carrying the spec's error contract
 * (code / message / messageAr / details / http status).
 */
class AsabException extends \Exception
{
    public function __construct(
        public string $errorCode,
        string $message,
        public ?string $messageAr = null,
        public int $status = 400,
        public array $details = []
    ) {
        parent::__construct($message);
    }
}

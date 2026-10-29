<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class MailDeliveryException extends RuntimeException
{
    public function __construct(
        string $message,
        ?Throwable $previous = null,
        public readonly ?int $emailId = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

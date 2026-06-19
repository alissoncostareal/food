<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentRefundException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $allowsManualCancel = false,
    ) {
        parent::__construct($message);
    }
}

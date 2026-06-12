<?php

namespace App\Contracts;

class CardChargeResult
{
    public function __construct(
        public readonly string $externalOrderId,
        public readonly ?string $externalChargeId,
        public readonly string $chargeStatus,
        public readonly ?string $failureMessage = null,
    ) {}

    public function isPaid(): bool
    {
        return in_array($this->chargeStatus, ['paid', 'approved', 'captured'], true);
    }

    public function isPending(): bool
    {
        return in_array($this->chargeStatus, ['pending', 'processing', 'authorized'], true);
    }

    public function isFailed(): bool
    {
        return ! $this->isPaid() && ! $this->isPending();
    }
}

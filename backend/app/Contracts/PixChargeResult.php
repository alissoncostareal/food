<?php

namespace App\Contracts;

class PixChargeResult
{
    public function __construct(
        public readonly string $externalOrderId,
        public readonly ?string $externalChargeId,
        public readonly ?string $qrCode,
        public readonly ?string $qrCodeUrl,
        public readonly mixed $expiresAt,
    ) {}
}

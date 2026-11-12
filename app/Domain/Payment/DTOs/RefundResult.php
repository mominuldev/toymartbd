<?php

declare(strict_types=1);

namespace App\Domain\Payment\DTOs;

use App\Domain\Payment\Enums\RefundStatus;

/** A driver's answer to a refund request. */
final readonly class RefundResult
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public RefundStatus $status,
        public ?string $gatewayReference = null,
        public array $payload = [],
    ) {}
}

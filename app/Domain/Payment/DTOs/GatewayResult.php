<?php

declare(strict_types=1);

namespace App\Domain\Payment\DTOs;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Support\Money;

/**
 * A driver's normalised answer (initiate / verify / collection). `payload` keeps the
 * provider's raw answer verbatim (DATABASE-DESIGN §1.7). `amount` is the provider's
 * authoritative paid amount — PaymentTransitions refuses `paid` when it differs.
 */
final readonly class GatewayResult
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public PaymentStatus $status,
        public ?Money $amount = null,
        public ?string $transactionId = null,
        public ?string $gatewayReference = null,
        public ?string $redirectUrl = null,
        public ?string $failureReason = null,
        public ?Money $fee = null,
        public array $payload = [],
    ) {}
}

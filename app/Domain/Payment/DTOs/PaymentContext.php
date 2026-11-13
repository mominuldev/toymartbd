<?php

declare(strict_types=1);

namespace App\Domain\Payment\DTOs;

/**
 * What a driver may need to open a payment with its provider. Carries no full phone or
 * address (only the district as `city`): a driver that needs the customer's details for a hosted page receives them
 * here at call time and must never log them (rule 20).
 */
final readonly class PaymentContext
{
    public function __construct(
        public string $orderNumber,
        public string $customerName,
        public string $customerPhone,
        public ?string $successUrl = null,
        public ?string $failureUrl = null,
        public string $locale = 'bn',
        public ?string $cancelUrl = null,
        public ?string $notifyUrl = null,
        public ?string $customerEmail = null,
        public ?string $city = null,
        public ?string $postcode = null,
    ) {}
}

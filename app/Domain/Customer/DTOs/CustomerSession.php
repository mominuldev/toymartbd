<?php

declare(strict_types=1);

namespace App\Domain\Customer\DTOs;

use App\Domain\Customer\Models\Customer;
use DateTimeInterface;

/**
 * A signed-in customer: the Sanctum token in plain text (shown once, to the Next.js
 * server only) and when it expires.
 */
final readonly class CustomerSession
{
    public function __construct(
        public Customer $customer,
        public string $plainTextToken,
        public DateTimeInterface $expiresAt,
        public bool $isNewCustomer,
    ) {}
}

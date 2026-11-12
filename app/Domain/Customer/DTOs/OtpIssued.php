<?php

declare(strict_types=1);

namespace App\Domain\Customer\DTOs;

/** What the storefront may tell the shopper after a code was sent — never the code. */
final readonly class OtpIssued
{
    public function __construct(
        public int $expiresIn,
        public int $resendIn,
        public int $length,
    ) {}
}

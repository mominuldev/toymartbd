<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Contracts;

use Carbon\CarbonImmutable;

/** A minted personal coupon: its id (for audit), the code to send, and when it stops working (UTC). */
final readonly class IssuedCoupon
{
    public function __construct(
        public int $id,
        public string $code,
        public CarbonImmutable $endsAt,
    ) {}
}

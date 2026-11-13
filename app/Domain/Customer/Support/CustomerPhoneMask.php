<?php

declare(strict_types=1);

namespace App\Domain\Customer\Support;

use App\Support\PhoneNumber;

/**
 * The masked phone the Customers screens show to every role — lists never carry a full
 * number (rule 20). Owner decision 2026-11-13: one mask everywhere, the same `017****5678`
 * that orders, logs and the profile header use (PhoneNumber::masked()), rather than
 * Figma B · 17's `01712•••318`, which revealed one more digit.
 */
final class CustomerPhoneMask
{
    public static function mask(string $phone): string
    {
        return PhoneNumber::tryParse($phone)?->masked() ?? '***';
    }
}

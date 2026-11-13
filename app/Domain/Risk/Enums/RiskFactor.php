<?php

declare(strict_types=1);

namespace App\Domain\Risk\Enums;

/**
 * The weighted signals of the risk score (DATABASE-DESIGN §5.2). Each contributes at most its
 * weight; the score is the plain sum of contributions, so the stored breakdown always adds up
 * to the score (weights are validated to total ≤ 100). Default weights are §5.2's; the owner
 * edits them on the Risk rules form (settings `risk.weights`).
 *
 * The first four are PHONE-level (our history with the phone) and make up the profile's
 * `internal_score`; the last three need the order in hand and only appear on an order's score.
 *
 * §5.2's "phone fails operator-prefix validation" is not a factor: rule 30 rejects such a phone
 * at the door, so it could never contribute.
 */
enum RiskFactor: string
{
    case CancellationRate = 'cancellation_rate';
    case CourierReturnRate = 'courier_return_rate';
    case FailedDelivery = 'failed_delivery';
    case FirstOrder = 'first_order';
    case ValueAboveNorm = 'value_above_norm';
    case AddressQuality = 'address_quality';
    case RapidRepeat = 'rapid_repeat';

    public function defaultWeight(): int
    {
        return match ($this) {
            self::CancellationRate, self::CourierReturnRate => 25,
            self::FailedDelivery => 15,
            self::FirstOrder, self::ValueAboveNorm => 10,
            self::AddressQuality, self::RapidRepeat => 5,
        };
    }

    public function isPhoneLevel(): bool
    {
        return in_array($this, [self::CancellationRate, self::CourierReturnRate, self::FailedDelivery, self::FirstOrder], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}

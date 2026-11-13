<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Services;

use App\Domain\Fulfilment\Exceptions\ShipmentViolation;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Shipment numbers `SHP-26-000001`: prefix, two-digit **Dhaka** year, a per-year counter
 * padded to six digits (Western digits, rule 36). The counter row is locked `FOR UPDATE`
 * inside the caller's transaction (RequestShipment), as for order numbers. The number is
 * also the merchant reference sent to the courier.
 */
final readonly class ShipmentNumberGenerator
{
    public const string PREFIX = 'SHP';

    public function next(?CarbonImmutable $at = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw ShipmentViolation::outsideTransaction();
        }

        $year = (int) DhakaDate::local($at ?? CarbonImmutable::now())->format('Y');

        DB::table('shipment_number_sequences')->insertOrIgnore(['year' => $year, 'last_value' => 0]);
        $last = (int) DB::table('shipment_number_sequences')->where('year', $year)->lockForUpdate()->value('last_value');
        $next = $last + 1;
        DB::table('shipment_number_sequences')->where('year', $year)->update(['last_value' => $next]);

        return sprintf('%s-%02d-%06d', self::PREFIX, $year % 100, $next);
    }
}

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
 *
 * {@see self::prepare()} creates the row BEFORE that transaction — never an unconditional
 * INSERT IGNORE inside it, which deadlocks two concurrent requests (P9,
 * docs/audits/LOAD-AND-CONCURRENCY.md F-2; ShipmentNumberRaceTest).
 */
final readonly class ShipmentNumberGenerator
{
    public const string PREFIX = 'SHP';

    /** Creates this Dhaka year's counter row if missing — call BEFORE the transaction (see OrderNumberGenerator::prepare()). */
    public function prepare(?CarbonImmutable $at = null): void
    {
        $year = self::year($at);
        if (! DB::table('shipment_number_sequences')->where('year', $year)->exists()) {
            DB::table('shipment_number_sequences')->insertOrIgnore(['year' => $year, 'last_value' => 0]);
        }
    }

    public function next(?CarbonImmutable $at = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw ShipmentViolation::outsideTransaction();
        }

        $year = self::year($at);
        $locked = static fn (): mixed => DB::table('shipment_number_sequences')->where('year', $year)->lockForUpdate()->value('last_value');

        // Lock first; insert only when the row is missing (prepare() skipped, or the year
        // turned in between). RequestShipment retries a deadlock on that rare path.
        $last = $locked();
        if ($last === null) {
            DB::table('shipment_number_sequences')->insertOrIgnore(['year' => $year, 'last_value' => 0]);
            $last = $locked();
        }
        $next = (int) $last + 1;
        DB::table('shipment_number_sequences')->where('year', $year)->update(['last_value' => $next]);

        return self::format($year, $next);
    }

    public static function format(int $year, int $sequence): string
    {
        return sprintf('%s-%02d-%06d', self::PREFIX, $year % 100, $sequence);
    }

    private static function year(?CarbonImmutable $at): int
    {
        return (int) DhakaDate::local($at ?? CarbonImmutable::now())->format('Y');
    }
}

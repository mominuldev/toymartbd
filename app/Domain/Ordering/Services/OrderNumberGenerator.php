<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Services;

use App\Domain\Ordering\Exceptions\OrderViolation;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Order numbers `TMB-26-00001` (DATABASE-DESIGN §3.4): prefix, two-digit **Dhaka** year
 * (an order at 23:30 UTC on 31 December is already next year's), then a per-year counter
 * padded to five digits (it simply grows past 99,999). Western digits (rule 36).
 *
 * The counter row is locked `FOR UPDATE` inside the caller's transaction (PlaceOrder):
 * concurrent checkouts serialise on it, and a rolled-back checkout returns its number.
 */
final readonly class OrderNumberGenerator
{
    public const string PREFIX = 'TMB';

    public function next(?CarbonImmutable $at = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw OrderViolation::outsideTransaction();
        }

        $year = (int) DhakaDate::local($at ?? CarbonImmutable::now())->format('Y');

        DB::table('order_number_sequences')->insertOrIgnore(['year' => $year, 'last_value' => 0]);
        $last = (int) DB::table('order_number_sequences')->where('year', $year)->lockForUpdate()->value('last_value');
        $next = $last + 1;
        DB::table('order_number_sequences')->where('year', $year)->update(['last_value' => $next]);

        return self::format($year, $next);
    }

    public static function format(int $year, int $sequence): string
    {
        return sprintf('%s-%02d-%05d', self::PREFIX, $year % 100, $sequence);
    }
}

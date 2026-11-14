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
 * {@see self::prepare()} creates the row BEFORE that transaction — never an unconditional
 * INSERT IGNORE inside it (P9, docs/audits/LOAD-AND-CONCURRENCY.md F-1; OrderNumberRaceTest).
 */
final readonly class OrderNumberGenerator
{
    public const string PREFIX = 'TMB';

    /**
     * Creates this Dhaka year's counter row if it is missing — call BEFORE the transaction.
     * A plain read first, so the common case (row exists) takes no lock at all; the INSERT
     * IGNORE runs only for the first order of a year, in autocommit, so its lock is released
     * at once. Inside a transaction an INSERT IGNORE that hits the existing row takes a
     * shared lock, and two checkouts holding it deadlock upgrading to FOR UPDATE.
     */
    public function prepare(?CarbonImmutable $at = null): void
    {
        $year = self::year($at);
        if (! DB::table('order_number_sequences')->where('year', $year)->exists()) {
            DB::table('order_number_sequences')->insertOrIgnore(['year' => $year, 'last_value' => 0]);
        }
    }

    public function next(?CarbonImmutable $at = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw OrderViolation::outsideTransaction();
        }

        $year = self::year($at);
        $locked = static fn (): mixed => DB::table('order_number_sequences')->where('year', $year)->lockForUpdate()->value('last_value');

        // Lock first. Only a missing row (prepare() skipped, or the Dhaka year turned between
        // the two calls) takes the insert path; a deadlock there is retried by the caller.
        $last = $locked();
        if ($last === null) {
            DB::table('order_number_sequences')->insertOrIgnore(['year' => $year, 'last_value' => 0]);
            $last = $locked();
        }
        $next = (int) $last + 1;
        DB::table('order_number_sequences')->where('year', $year)->update(['last_value' => $next]);

        return self::format($year, $next);
    }

    public static function format(int $year, int $sequence): string
    {
        return sprintf('%s-%02d-%05d', self::PREFIX, $year % 100, $sequence);
    }

    private static function year(?CarbonImmutable $at): int
    {
        return (int) DhakaDate::local($at ?? CarbonImmutable::now())->format('Y');
    }
}

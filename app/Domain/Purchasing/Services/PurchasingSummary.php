<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\Supplier;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The Purchase orders list's KPI row (Figma "Purchasing · Premium" 414:70079). Reads only.
 *
 *  - open:     POs waiting for stock (ordered or partly received) and what was ordered (goods);
 *  - arriving: open POs expected between today and the end of this Saturday–Friday week
 *              (the shop's week, owner decision; Dhaka dates, rule 11);
 *  - late:     open POs whose expected date is before today;
 *  - owed:     what we owe suppliers for stock already received — the sum of every positive
 *              supplier balance (SupplierBalances: opening + receipts − payments), and how many
 *              suppliers that is. An advance (negative balance) never nets another supplier's debt.
 */
final readonly class PurchasingSummary
{
    /** Carbon weekday number of the week's last day: 5 = Friday (weeks run Saturday–Friday). */
    public const int WEEK_ENDS_ON = CarbonImmutable::FRIDAY;

    public function __construct(private SupplierBalances $balances) {}

    /**
     * @return array{today: string, week_ends: string, open_count: int, open_value: Money, arriving_count: int, late_count: int, owed: Money, owed_suppliers: int}
     */
    public function compute(): array
    {
        $today = DhakaDate::today();
        $weekEnds = self::weekEnds($today);

        $open = Purchase::query()->whereIn('status', self::openStatuses());
        // What was ordered (goods), never a receipt's extra costs — old POs included.
        $value = (clone $open)->sum(DB::raw(PurchaseTotals::ORDERED_SQL));

        $owed = Money::zero();
        $owedSuppliers = 0;
        foreach ($this->balances->forMany(Supplier::query()->get()) as $balance) {
            if ($balance['balance']->greaterThan(Money::zero())) {
                $owed = $owed->plus($balance['balance']);
                $owedSuppliers++;
            }
        }

        return [
            'today' => $today,
            'week_ends' => $weekEnds,
            'open_count' => (clone $open)->count(),
            'open_value' => Money::of(bcadd((string) $value, '0', 2)),
            'arriving_count' => self::arriving(clone $open, $today, $weekEnds)->count(),
            'late_count' => self::late(clone $open, $today)->count(),
            'owed' => $owed,
            'owed_suppliers' => $owedSuppliers,
        ];
    }

    /** @return list<string> */
    public static function openStatuses(): array
    {
        return [PurchaseStatus::Ordered->value, PurchaseStatus::PartiallyReceived->value];
    }

    /** The Friday that ends the Saturday–Friday week holding `$today` (Y-m-d). */
    public static function weekEnds(string $today): string
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $today) ?: CarbonImmutable::now();

        return $day->addDays((self::WEEK_ENDS_ON - $day->dayOfWeek + 7) % 7)->toDateString();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  already limited to open POs
     * @return Builder<TModel>
     */
    public static function arriving(Builder $query, string $today, string $weekEnds): Builder
    {
        return $query->whereNotNull('expected_date')
            ->whereDate('expected_date', '>=', $today)
            ->whereDate('expected_date', '<=', $weekEnds);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  already limited to open POs
     * @return Builder<TModel>
     */
    public static function late(Builder $query, string $today): Builder
    {
        return $query->whereNotNull('expected_date')->whereDate('expected_date', '<', $today);
    }
}

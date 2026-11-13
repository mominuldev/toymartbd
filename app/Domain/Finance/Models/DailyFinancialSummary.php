<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\LedgerEntryType;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One Dhaka day of the nightly finance rollup (DATABASE-DESIGN §3.7). A projection: written
 * only by {@see \App\Domain\Finance\Services\DailySummaryRollup}, rebuildable from the ledger.
 * One Money column per {@see LedgerEntryType} value, in the type's natural direction.
 *
 * @property Carbon $summary_date
 * @property Money $recognised_in
 * @property Money $recognised_out
 * @property Money $net_profit
 * @property Money $cash_in
 * @property Money $cash_out
 * @property Money $net_cash
 * @property int $orders_placed
 * @property int $orders_delivered
 * @property int $orders_cancelled
 * @property int $orders_returned
 * @property int $new_customers
 * @property int $units_sold
 * @property Money $cod_receivable_closing
 * @property Carbon|null $computed_at
 */
final class DailyFinancialSummary extends Model
{
    public const array TOTAL_COLUMNS = ['recognised_in', 'recognised_out', 'net_profit', 'cash_in', 'cash_out', 'net_cash', 'cod_receivable_closing'];

    public const array COUNT_COLUMNS = ['orders_placed', 'orders_delivered', 'orders_cancelled', 'orders_returned', 'new_customers', 'units_sold'];

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'summary_date';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /** @return list<string> */
    public static function moneyColumns(): array
    {
        return [...LedgerEntryType::values(), ...self::TOTAL_COLUMNS];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return array_fill_keys(self::moneyColumns(), MoneyCast::class)
            + array_fill_keys(self::COUNT_COLUMNS, 'integer')
            + ['summary_date' => 'date:Y-m-d', 'computed_at' => 'datetime'];
    }
}

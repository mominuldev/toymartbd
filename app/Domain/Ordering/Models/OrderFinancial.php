<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The per-order P&L projection (DATABASE-DESIGN §3.4, §5.4). Computed from the
 * financial_transactions ledger by Finance (Phase 5); invariant §7.2 compares the two.
 *
 * @property int $order_id
 * @property Money $revenue
 * @property Money $cogs
 * @property Money $gross_profit
 * @property Money $net_profit
 * @property string|null $margin_percent
 * @property Carbon|null $computed_at
 */
final class OrderFinancial extends Model
{
    public const array MONEY_COLUMNS = [
        'revenue', 'cogs', 'gross_profit', 'discount_cost', 'courier_charge', 'courier_cod_fee',
        'courier_return_charge', 'gateway_fee', 'packaging_cost', 'allocated_marketing_cost',
        'refund_amount', 'return_loss', 'net_profit',
    ];

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'order_id';

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return array_fill_keys(self::MONEY_COLUMNS, MoneyCast::class) + [
            'order_id' => 'integer',
            'margin_percent' => 'decimal:2',
            'computed_at' => 'datetime',
        ];
    }
}

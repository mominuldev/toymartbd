<?php

declare(strict_types=1);

namespace App\Domain\Payment\Models;

use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $refund_id
 * @property int $order_item_id
 * @property int $quantity
 * @property Money $amount
 */
final class RefundItem extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['refund_id', 'order_item_id', 'quantity', 'amount'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'refund_id' => 'integer',
            'order_item_id' => 'integer',
            'quantity' => 'integer',
            'amount' => MoneyCast::class,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One promotion applied to one order (P7). Written and reversed only by
 * {@see \App\Domain\Marketing\Services\PromotionUsages}.
 *
 * @property int $id
 * @property int $promotion_id
 * @property int $order_id
 * @property int|null $customer_id
 * @property string $phone
 * @property Money $discount_amount
 * @property array<int|string, int>|null $flash_units variant id → units claimed
 * @property Carbon $used_at
 * @property-read Promotion $promotion
 */
final class PromotionUsage extends Model
{
    /** @var list<string> */
    protected $fillable = ['promotion_id', 'order_id', 'customer_id', 'phone', 'discount_amount', 'flash_units', 'used_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'promotion_id' => 'integer',
            'order_id' => 'integer',
            'customer_id' => 'integer',
            'discount_amount' => MoneyCast::class,
            'flash_units' => 'array',
            'used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Promotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}

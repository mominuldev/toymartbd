<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One redemption of a coupon by one order (DATABASE-DESIGN §3.8). `phone` is E.164 —
 * the per-customer limit key that survives guest checkout. Written and reversed only
 * by {@see \App\Domain\Marketing\Services\CouponUsages}.
 *
 * @property int $id
 * @property int $coupon_id
 * @property int $order_id
 * @property int|null $customer_id
 * @property string $phone
 * @property Money $discount_amount
 * @property Carbon $used_at
 * @property-read Coupon $coupon
 */
final class CouponUsage extends Model
{
    /** @var list<string> */
    protected $fillable = ['coupon_id', 'order_id', 'customer_id', 'phone', 'discount_amount', 'used_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'coupon_id' => 'integer',
            'order_id' => 'integer',
            'customer_id' => 'integer',
            'discount_amount' => MoneyCast::class,
            'used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}

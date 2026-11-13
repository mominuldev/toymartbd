<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

use App\Domain\Marketing\Enums\CouponType;
use App\Domain\Marketing\Enums\ProductScopeType;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A coupon (DATABASE-DESIGN §3.8, P4-CRT-04). Validated by CouponEvaluator; usage is
 * written only by CouponUsages::record(), inside the order's transaction.
 *
 * @property int $id
 * @property string $code
 * @property string|null $description
 * @property string|null $batch bulk-generated codes share it (P7)
 * @property CouponType $type
 * @property Money $value
 * @property Money|null $max_discount
 * @property Money $min_order_amount
 * @property int|null $usage_limit_total
 * @property int|null $usage_limit_per_customer
 * @property int $used_count
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property ProductScopeType $applies_to
 * @property array<string, mixed>|null $conditions `{ids: list<int>}` for the `applies_to` scope
 * @property array<string, mixed>|null $excludes `{product_ids?, category_ids?, brand_ids?}`
 * @property bool $first_order_only
 * @property int|null $customer_segment_id
 * @property bool $is_active
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CouponUsage> $usages
 */
final class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    public const int CODE_MAX = 40;

    /** @var list<string> */
    protected $fillable = [
        'code', 'description', 'batch', 'type', 'value', 'max_discount', 'min_order_amount', 'usage_limit_total',
        'usage_limit_per_customer', 'starts_at', 'ends_at', 'applies_to', 'conditions', 'excludes',
        'first_order_only', 'customer_segment_id', 'is_active', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'value' => '0.00',
        'min_order_amount' => '0.00',
        'used_count' => 0,
        'applies_to' => 'all',
        'first_order_only' => false,
        'is_active' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'value' => MoneyCast::class,
            'max_discount' => MoneyCast::class,
            'min_order_amount' => MoneyCast::class,
            'usage_limit_total' => 'integer',
            'usage_limit_per_customer' => 'integer',
            'used_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'applies_to' => ProductScopeType::class,
            'conditions' => 'array',
            'excludes' => 'array',
            'first_order_only' => 'boolean',
            'customer_segment_id' => 'integer',
            'is_active' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    /** Codes are stored upper-case; customers type them any way. */
    public static function normaliseCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    public static function findByCode(string $code): ?self
    {
        return self::query()->where('code', self::normaliseCode($code))->first();
    }

    /** @return HasMany<CouponUsage, $this> */
    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    /** @return list<int> */
    public function scopeIds(): array
    {
        $ids = $this->conditions['ids'] ?? [];

        return is_array($ids) ? array_values(array_map('intval', array_filter($ids, 'is_numeric'))) : [];
    }

    protected static function newFactory(): CouponFactory
    {
        return CouponFactory::new();
    }
}

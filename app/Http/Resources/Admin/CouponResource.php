<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Marketing\Models\Coupon;
use App\Http\Resources\ApiResource;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A coupon in the admin (P4-CRT-04). Money is a decimal string; times are ISO 8601 UTC.
 *
 * @property Coupon $resource
 */
#[SchemaName('Coupon')]
final class CouponResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $coupon = $this->resource;

        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'description' => $coupon->description,
            /** Bulk-generated codes share a batch label (coupon builder, P7). */
            'batch' => $coupon->batch,
            /** @var 'fixed'|'percent'|'free_shipping' */
            'type' => $coupon->type->value,
            /** @var string */
            'value' => $coupon->value->toDecimal(),
            /** @var string|null */
            'max_discount' => $coupon->max_discount?->toDecimal(),
            /** @var string */
            'min_order_amount' => $coupon->min_order_amount->toDecimal(),
            'usage_limit_total' => $coupon->usage_limit_total,
            'usage_limit_per_customer' => $coupon->usage_limit_per_customer,
            'used_count' => $coupon->used_count,
            /** @var string|null */
            'starts_at' => self::utcIso($coupon->starts_at),
            /** @var string|null */
            'ends_at' => self::utcIso($coupon->ends_at),
            /** @var 'all'|'products'|'categories'|'brands' */
            'applies_to' => $coupon->applies_to->value,
            /** @var list<int> */
            'scope_ids' => $coupon->scopeIds(),
            /** @var array{product_ids?: list<int>, category_ids?: list<int>, brand_ids?: list<int>} */
            'excludes' => (object) ($coupon->excludes ?? []),
            'first_order_only' => $coupon->first_order_only,
            'is_active' => $coupon->is_active,
            /** @var string|null */
            'created_at' => self::utcIso($coupon->created_at),
            /** @var string|null */
            'updated_at' => self::utcIso($coupon->updated_at),
        ];
    }

    private static function utcIso(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}

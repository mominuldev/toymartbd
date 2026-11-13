<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Marketing\Models\FlashSaleItem;
use App\Domain\Marketing\Models\Promotion;
use App\Http\Resources\ApiResource;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A promotion in the admin (P7, docs/api/promotions.md). Money is a decimal string; times
 * are ISO 8601 UTC (the admin shows them in Dhaka time). `status` is computed at request
 * time: `scheduled` | `running` | `ended` | `inactive`.
 *
 * @property Promotion $resource
 */
#[SchemaName('Promotion')]
final class PromotionResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $p->loadMissing('flashItems.variant.product');

        return [
            'id' => $p->id,
            'name' => $p->name,
            'name_bn' => $p->name_bn,
            'description' => $p->description,
            /** @var 'discount'|'flash_sale'|'category_discount'|'tiered'|'buy_x_get_y'|'bundle'|'free_delivery' */
            'type' => $p->type->value,
            'priority' => $p->priority,
            'is_stackable' => $p->is_stackable,
            'allows_coupons' => $p->allows_coupons,
            /** @var array<string, mixed> */
            'rules' => (object) $p->rules,
            /** @var string|null */
            'starts_at' => self::utcIso($p->starts_at),
            /** @var string|null */
            'ends_at' => self::utcIso($p->ends_at),
            'is_active' => $p->is_active,
            /** @var 'scheduled'|'running'|'ended'|'inactive' */
            'status' => $p->statusAt(now()),
            'usage_count' => $p->usage_count,
            'usage_limit_total' => $p->usage_limit_total,
            'usage_limit_per_customer' => $p->usage_limit_per_customer,
            /** @var string|null */
            'budget' => $p->budget?->toDecimal(),
            /** @var string */
            'discount_spent' => $p->discount_spent->toDecimal(),
            /** @var list<array{id: int, variant_id: int, sku: string|null, product_name: string|null, product_name_bn: string|null, variant_name: string|null, regular_price: string|null, sale_price: string, allotment: int, claimed: int, remaining: int, per_order_limit: int|null}> */
            'flash_items' => array_values($p->flashItems->map(static fn (FlashSaleItem $i): array => [
                'id' => $i->id,
                'variant_id' => $i->variant_id,
                'sku' => $i->variant->sku,
                'product_name' => $i->variant->product->name,
                'product_name_bn' => $i->variant->product->name_bn,
                'variant_name' => $i->variant->name,
                'regular_price' => $i->variant->price->toDecimal(),
                'sale_price' => $i->sale_price->toDecimal(),
                'allotment' => $i->allotment,
                'claimed' => $i->claimed,
                'remaining' => $i->remaining(),
                'per_order_limit' => $i->per_order_limit,
            ])->all()),
            /** @var string|null */
            'created_at' => self::utcIso($p->created_at),
            /** @var string|null */
            'updated_at' => self::utcIso($p->updated_at),
        ];
    }

    private static function utcIso(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}

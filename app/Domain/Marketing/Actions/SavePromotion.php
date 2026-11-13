<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\DTOs\PromotionData;
use App\Domain\Marketing\Enums\PromotionType;
use App\Domain\Marketing\Events\PromotionChanged;
use App\Domain\Marketing\Models\FlashSaleItem;
use App\Domain\Marketing\Models\Promotion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or replace a promotion (admin, P7), with its flash-sale lines. Flash lines are
 * locked FOR UPDATE (ascending variant id) while they are replaced, so an allotment can
 * never be set below the units already claimed by open orders, and a line with claimed
 * units cannot be removed — end the sale or lower the allotment to `claimed` instead.
 * Emits PromotionChanged (after commit) so the storefront revalidates.
 */
final class SavePromotion
{
    public function handle(PromotionData $data, ?Promotion $promotion = null, ?int $userId = null): Promotion
    {
        return DB::transaction(function () use ($data, $promotion, $userId): Promotion {
            if ($promotion === null) {
                $promotion = new Promotion;
                $promotion->created_by = $userId;
            } else {
                $promotion = Promotion::query()->lockForUpdate()->findOrFail($promotion->id);
            }
            $previousSlugs = self::slugs($promotion);

            $promotion->fill($data->toAttributes());
            $promotion->save();

            $items = $data->type === PromotionType::FlashSale ? $data->flashItems : [];
            $this->syncFlashItems($promotion, $items);

            $promotion->refresh()->load('flashItems');
            PromotionChanged::dispatch(
                $promotion->id,
                $promotion->type === PromotionType::FlashSale,
                array_values(array_unique([...$previousSlugs, ...self::slugs($promotion)])),
            );

            return $promotion;
        });
    }

    /** @param  list<array{variant_id: int, sale_price: \App\Support\Money, allotment: int, per_order_limit: int|null}>  $items */
    private function syncFlashItems(Promotion $promotion, array $items): void
    {
        $existing = FlashSaleItem::query()->where('promotion_id', $promotion->id)->orderBy('variant_id')->lockForUpdate()->get()->keyBy('variant_id');
        $wanted = [];
        foreach ($items as $item) {
            $wanted[$item['variant_id']] = $item;
        }
        ksort($wanted);

        $errors = [];
        foreach ($existing as $variantId => $row) {
            if (! isset($wanted[$variantId]) && $row->claimed > 0) {
                $errors['flash_items'] = "Variant {$variantId} has {$row->claimed} units on open orders and cannot be removed; lower its allotment to {$row->claimed} instead.";
            }
        }
        $i = 0;
        foreach ($wanted as $variantId => $item) {
            $row = $existing->get($variantId);
            if ($row !== null && $item['allotment'] < $row->claimed) {
                $errors["flash_items.{$i}.allotment"] = "The allotment cannot go below the {$row->claimed} units already on open orders.";
            }
            $i++;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($existing as $variantId => $row) {
            if (! isset($wanted[$variantId])) {
                $row->delete();
            }
        }
        foreach ($wanted as $variantId => $item) {
            $row = $existing->get($variantId) ?? new FlashSaleItem(['promotion_id' => $promotion->id, 'variant_id' => $variantId]);
            $row->fill([
                'sale_price' => $item['sale_price'],
                'allotment' => $item['allotment'],
                'per_order_limit' => $item['per_order_limit'],
            ]);
            $row->save();
        }
    }

    /** @return list<string> */
    public static function slugs(Promotion $promotion): array
    {
        if (! $promotion->exists) {
            return [];
        }

        return array_values(array_map('strval', DB::table('flash_sale_items')
            ->join('product_variants', 'product_variants.id', '=', 'flash_sale_items.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('flash_sale_items.promotion_id', $promotion->id)
            ->distinct()
            ->pluck('products.slug')
            ->all()));
    }
}

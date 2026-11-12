<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront\Account;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Http\Resources\ApiResource;
use App\Http\Resources\Storefront\StorefrontOrderResource;
use DateTimeImmutable;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One row of "My orders" (P4-STO-11, Figma AccountOrderCard 236:2302): the customer-facing
 * stage (StorefrontOrderResource::stage — an order on hold reads `placed`), totals as the
 * API computed them, and up to three item thumbnails. Load `items` first.
 *
 * @property Order $resource
 */
#[SchemaName('StorefrontOrderSummary')]
final class StorefrontOrderSummaryResource extends ApiResource
{
    public const int PREVIEW_ITEMS = 3;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $items = $order->items;

        return [
            'number' => $order->number,
            /** @var 'placed'|'confirmed'|'processing'|'shipped'|'delivered'|'cancelled'|'returned' */
            'stage' => StorefrontOrderResource::stage($order->status),
            /** @var string|null */
            'placed_at' => $order->placed_at === null ? null
                : DateTimeImmutable::createFromInterface($order->placed_at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            /** Units across all lines. */
            'item_count' => (int) $items->sum('quantity'),
            /** @var string */
            'grand_total' => $order->grand_total->toDecimal(),
            /** @var array{method: string|null, status: string} */
            'payment' => ['method' => $order->payment_method, 'status' => $order->payment_status->value],
            /** @var list<array{name: string, name_bn: string|null, image: array{url: string, alt: string|null, alt_bn: string|null}|null}> */
            'preview' => self::preview($order),
            /**
             * Lines beyond the preview ("+1").
             *
             * @var int
             */
            'more_items' => max(0, $items->count() - self::PREVIEW_ITEMS),
        ];
    }

    /** @return list<array{name: string, name_bn: string|null, image: array{url: string, alt: string|null, alt_bn: string|null}|null}> */
    private static function preview(Order $order): array
    {
        $lines = $order->items->take(self::PREVIEW_ITEMS);
        $products = Product::query()->withTrashed()->with('images')
            ->whereKey($lines->pluck('product_id')->filter()->unique()->values()->all())
            ->get()->keyBy('id');

        return $lines->map(static function (OrderItem $item) use ($products): array {
            /** @var Product|null $product */
            $product = $item->product_id !== null ? $products->get($item->product_id) : null;
            $images = $product?->images->sortBy('position');
            /** @var ProductImage|null $image */
            $image = $images === null ? null
                : (($item->image_path !== null ? $images->firstWhere('path', $item->image_path) : null)
                    ?? $images->firstWhere('is_primary', true) ?? $images->first());
            $url = $image?->url(160);

            return [
                'name' => $item->product_name,
                'name_bn' => $item->product_name_bn,
                'image' => $image === null || $url === null ? null : ['url' => $url, 'alt' => $image->alt('en'), 'alt_bn' => $image->alt('bn')],
            ];
        })->values()->all();
    }
}

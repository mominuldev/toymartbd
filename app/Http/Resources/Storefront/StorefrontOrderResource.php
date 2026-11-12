<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Division;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Ordering\Enums\OrderEventType;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use App\Http\Resources\ApiResource;
use App\Support\PhoneNumber;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * An order as its customer sees it (docs/api/checkout.md `StorefrontOrder`): the checkout
 * response, the confirmation page and public tracking.
 *
 * `stage` is the customer-facing status — an order held for a verification call reads
 * `placed`, never "on hold". Never present: cost prices, risk, internal notes, the full
 * phone, the customer id. {@see forTracking()} also drops the street address and the
 * surname: a matched 4-digit guess must not reveal where a family lives.
 *
 * @property Order $resource
 */
#[SchemaName('StorefrontOrder')]
final class StorefrontOrderResource extends ApiResource
{
    private bool $public = false;

    /** For `POST /orders/track`: no street address, first name only. */
    public static function forTracking(Order $order): self
    {
        $resource = new self($order);
        $resource->public = true;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $order->loadMissing(['items', 'events', 'customer']);
        $masked = PhoneNumber::tryParse($order->shipping_phone)?->masked() ?? '***';
        $name = $this->public ? self::firstName($order->shipping_name) : $order->shipping_name;

        return [
            /** @var string */
            'number' => $order->number,
            /**
             * Customer-facing status code; localise the label in the storefront.
             *
             * @var 'placed'|'confirmed'|'processing'|'shipped'|'delivered'|'cancelled'|'returned'
             */
            'stage' => self::stage($order->status),
            /** @var string|null */
            'placed_at' => self::iso($order->placed_at),
            /**
             * `next_action` is null for cash on delivery; for an online order whose live
             * gateway session is still open it is `{type: "redirect", url}`. `retry_allowed`:
             * the payment-failed page may offer `POST /orders/{number}/payment` again.
             * `expires_at`: when the unpaid online order's stock hold lapses (null for COD).
             *
             * @var array{method: string|null, status: string, next_action: array{type: string, url: string}|null, online: bool, retry_allowed: bool, expires_at: string|null}
             */
            'payment' => self::payment($order, $this->public),
            /** @var array{name: string, phone_masked: string, email: string|null} */
            'contact' => [
                'name' => $name,
                'phone_masked' => $masked,
                'email' => $this->public ? null : $order->customer->email,
            ],
            /**
             * Tracking (`POST /orders/track`) returns `address_line`, `landmark` and `postcode` as null.
             *
             * @var array{name: string, phone_masked: string, division: array{id: int, name: string, name_bn: string}|null, district: array{id: int, name: string, name_bn: string}|null, upazila: array{id: int, name: string, name_bn: string}|null, area: string|null, address_line: string|null, landmark: string|null, postcode: string|null}
             */
            'shipping' => [
                'name' => $name,
                'phone_masked' => $masked,
                'division' => self::place(Division::class, $order->shipping_division_id),
                'district' => self::place(District::class, $order->shipping_district_id),
                'upazila' => self::place(Upazila::class, $order->shipping_upazila_id),
                'area' => $order->shipping_area,
                'address_line' => $this->public ? null : $order->shipping_address,
                'landmark' => $this->public ? null : $order->shipping_landmark,
                'postcode' => $this->public ? null : $order->shipping_postcode,
            ],
            /** @var list<array{variant_id: int|null, product_id: int|null, slug: string|null, name: string, name_bn: string|null, variant_name: string|null, sku: string, image: array{url: string, alt: string|null, alt_bn: string|null}|null, quantity: int, unit_price: string, discount: string, total: string}> */
            'items' => self::items($order->items),
            /** @var string|null */
            'coupon_code' => $order->coupon_code,
            /** @var array{subtotal: string, promotion_discount: string, coupon_discount: string, discount_total: string, shipping_charge: string, grand_total: string, paid_total: string, due_total: string} */
            'totals' => [
                'subtotal' => $order->subtotal->toDecimal(),
                'promotion_discount' => $order->promotion_discount->toDecimal(),
                'coupon_discount' => $order->coupon_discount->toDecimal(),
                'discount_total' => $order->discount_total->toDecimal(),
                'shipping_charge' => $order->shipping_charge->toDecimal(),
                'grand_total' => $order->grand_total->toDecimal(),
                'paid_total' => $order->paid_total->toDecimal(),
                'due_total' => $order->due_total->toDecimal(),
            ],
            /**
             * Customer-visible events, oldest first.
             *
             * @var list<array{stage: 'placed'|'confirmed'|'processing'|'shipped'|'delivered'|'cancelled'|'returned', at: string|null}>
             */
            'timeline' => self::timeline($order->events),
        ];
    }

    /** @return array<string, mixed> */
    private static function payment(Order $order, bool $public): array
    {
        $method = PaymentMethod::tryFrom((string) $order->payment_method);
        $online = $method !== null && $method->isOnline();
        /** @var Payment|null $latest */
        $latest = $online ? $order->payments()->reorder()->orderByDesc('id')->first() : null;
        $url = $latest?->status === PaymentStatus::Initiated ? ($latest->gateway_payload['GatewayPageURL'] ?? null) : null;
        // Public tracking (number + 4 digits) never gets a session URL or a retry.
        $open = ! $public && in_array($order->status, [OrderStatus::Pending, OrderStatus::OnHold], true);
        $retry = $online && $open && $latest !== null && $latest->review_reason === null
            && in_array($latest->status, [PaymentStatus::Pending, PaymentStatus::Failed, PaymentStatus::Cancelled], true);

        return [
            'method' => $order->payment_method,
            'status' => $order->payment_status->value,
            'next_action' => $open && is_string($url) && $url !== '' ? ['type' => 'redirect', 'url' => $url] : null,
            'online' => $online,
            'retry_allowed' => $retry,
            'expires_at' => $online && $open ? self::iso($latest?->expires_at) : null,
        ];
    }

    public static function stage(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Pending, OrderStatus::OnHold => 'placed',
            OrderStatus::Confirmed => 'confirmed',
            OrderStatus::Processing, OrderStatus::ReadyToShip => 'processing',
            OrderStatus::Shipped => 'shipped',
            OrderStatus::Delivered, OrderStatus::Completed => 'delivered',
            OrderStatus::Cancelled => 'cancelled',
            OrderStatus::Returned, OrderStatus::PartiallyReturned => 'returned',
        };
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @return list<array<string, mixed>>
     */
    private static function items(Collection $items): array
    {
        $products = Product::query()->withTrashed()->with('images')
            ->whereKey($items->pluck('product_id')->filter()->unique()->values()->all())
            ->get()->keyBy('id');

        return $items->map(static function (OrderItem $item) use ($products): array {
            /** @var Product|null $product */
            $product = $item->product_id !== null ? $products->get($item->product_id) : null;
            $image = self::image($product, $item);

            return [
                'variant_id' => $item->variant_id,
                'product_id' => $item->product_id,
                'slug' => $product !== null && ! $product->trashed() ? $product->slug : null,
                'name' => $item->product_name,
                'name_bn' => $item->product_name_bn,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'image' => $image,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price->toDecimal(),
                'discount' => $item->discount->toDecimal(),
                'total' => $item->line_total->toDecimal(),
            ];
        })->values()->all();
    }

    /** @return array{url: string, alt: string|null, alt_bn: string|null}|null */
    private static function image(?Product $product, OrderItem $item): ?array
    {
        if ($product === null) {
            return null;
        }
        $images = $product->images->sortBy('position');
        /** @var ProductImage|null $image */
        $image = ($item->image_path !== null ? $images->firstWhere('path', $item->image_path) : null)
            ?? $images->firstWhere('is_primary', true)
            ?? $images->first();
        $url = $image?->url(160);

        return $image === null || $url === null ? null : ['url' => $url, 'alt' => $image->alt('en'), 'alt_bn' => $image->alt('bn')];
    }

    /**
     * @param  Collection<int, OrderEvent>  $events
     * @return list<array{stage: string, at: string|null}>
     */
    private static function timeline(Collection $events): array
    {
        $rows = [];
        foreach ($events as $event) {
            if (! $event->is_customer_visible || $event->field !== 'status') {
                continue;
            }
            $stage = $event->type === OrderEventType::Placed || $event->to_status === null
                ? 'placed'
                : self::stage(OrderStatus::from($event->to_status));
            if ($rows !== [] && $rows[array_key_last($rows)]['stage'] === $stage) {
                continue;
            }
            $rows[] = ['stage' => $stage, 'at' => self::iso($event->created_at)];
        }

        return $rows;
    }

    /**
     * @param  class-string<Division|District|Upazila>  $model
     * @return array{id: int, name: string, name_bn: string}|null
     */
    private static function place(string $model, ?int $id): ?array
    {
        if ($id === null) {
            return null;
        }
        /** @var Division|District|Upazila|null $row */
        $row = $model::query()->find($id);

        return $row === null ? null : ['id' => $row->id, 'name' => $row->name, 'name_bn' => $row->name_bn];
    }

    private static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];

        return $parts[0] ?? '';
    }

    private static function iso(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}

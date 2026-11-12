<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Queries;

use App\Domain\Cart\Services\SellableVariants;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Address;
use App\Domain\Customer\Models\Customer;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read side of the create-order screen (P4-ORD-06): the product picker and the phone
 * lookup. Read-only.
 */
final readonly class OrderComposerLookups
{
    public const int VARIANT_RESULTS = 20;

    public const int OPEN_ORDERS = 5;

    public const int ADDRESSES = 5;

    public function __construct(private SellableVariants $sellable) {}

    /**
     * Sellable variants matching a name (English or Bangla), SKU or barcode — with the
     * stock available now, so the picker can warn before the quote does.
     *
     * @return list<array{variant: ProductVariant, available: int}>
     */
    public function variants(string $term): array
    {
        $like = '%'.addcslashes($term, '\\%_').'%';
        $candidates = ProductVariant::query()
            ->with(['product' => fn ($q) => $q->withTrashed(), 'product.images'])
            ->where(static function (Builder $q) use ($term, $like): void {
                $q->where('sku', 'like', $like)
                    ->orWhere('barcode', $term)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('name_bn', 'like', $like)
                    ->orWhereHas('product', static fn (Builder $p) => $p->where('name', 'like', $like)->orWhere('name_bn', 'like', $like));
            })
            ->orderBy('product_id')->orderBy('position')->orderBy('id')
            ->limit(self::VARIANT_RESULTS * 3)
            ->get();

        $sellable = $candidates->filter(fn (ProductVariant $v): bool => $this->sellable->isSellable($v))->take(self::VARIANT_RESULTS)->values();
        $levels = StockLevel::query()->where('location_id', StockLocation::defaultId())
            ->whereIn('variant_id', $sellable->pluck('id')->all())->get()->keyBy('variant_id');

        return $sellable->map(static function (ProductVariant $v) use ($levels): array {
            $level = $levels[$v->id] ?? null;

            return ['variant' => $v, 'available' => $level instanceof StockLevel ? max(0, $level->available()) : 0];
        })->all();
    }

    /**
     * What the shop knows about a phone: the customer (by E.164, rule 30), their saved
     * addresses (default first) and their still-open orders (pre-shipment).
     *
     * @return array{customer: Customer|null, addresses: Collection<int, Address>, open_orders: Collection<int, Order>}
     */
    public function customer(PhoneNumber $phone): array
    {
        $customer = Customer::query()->where('phone', $phone->e164())->first();
        if ($customer === null) {
            return ['customer' => null, 'addresses' => new Collection, 'open_orders' => new Collection];
        }

        $addresses = Address::query()->with(['division', 'district', 'upazila'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('is_default_shipping')->orderByDesc('id')
            ->limit(self::ADDRESSES)->get();
        $open = Order::query()->where('customer_id', $customer->id)
            ->whereIn('status', array_map(static fn (OrderStatus $s): string => $s->value, array_filter(OrderStatus::cases(), static fn (OrderStatus $s): bool => $s->holdsReservation())))
            ->orderByDesc('id')->limit(self::OPEN_ORDERS)->get();

        return ['customer' => $customer, 'addresses' => $addresses, 'open_orders' => $open];
    }
}

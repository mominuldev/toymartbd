<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'variant_id' => ProductVariant::factory(),
            'product_id' => static fn (array $a): ?int => ProductVariant::query()->whereKey($a['variant_id'])->value('product_id'),
            'product_name' => 'Wooden Puzzle Set',
            'product_name_bn' => 'কাঠের পাজল সেট',
            'sku' => static fn (array $a): string => ProductVariant::query()->whereKey($a['variant_id'])->value('sku') ?? 'TEST-SKU',
            'unit_price' => '1250.00',
            'quantity' => 1,
            'line_total' => '1250.00',
            'cost_price_snapshot' => '800.0000',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare order row (no items, no reservation, no payment) for list/filter/projection
 * tests. For a realistic order — items snapshotted from variants, stock reserved, a
 * payment opened, a `placed` event — use Tests\Feature\Ordering\Support\OrderFixtures.
 *
 * Totals obey §7.3: grand_total = subtotal − discount_total + shipping_charge + tax_total.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    private static int $sequence = 0;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'number' => sprintf('TMB-26-%05d', ++self::$sequence + 90000),
            'customer_id' => Customer::factory(),
            'status' => OrderStatus::Pending,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'fulfillment_status' => FulfillmentStatus::Unfulfilled,
            'source' => 'web',
            'payment_method' => 'cod',
            'subtotal' => '1250.00',
            'shipping_charge' => '70.00',
            'grand_total' => '1320.00',
            'due_total' => '1320.00',
            'shipping_name' => 'নুসরাত জাহান',
            'shipping_phone' => static fn (array $attributes): string => Customer::query()->whereKey($attributes['customer_id'])->value('phone') ?? CustomerFactory::phone(),
            'shipping_division' => 'ঢাকা',
            'shipping_district' => 'ঢাকা',
            'shipping_area' => 'মিরপুর',
            'shipping_address' => 'বাড়ি 7, রোড 3, সেকশন 10',
            'shipping_landmark' => 'মিরপুর 10 গোলচত্বরের কাছে',
            'placed_at' => now(),
        ];
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    /** grand_total (and due_total) in taka, with no shipping, for projection tests. */
    public function total(string $amount): static
    {
        return $this->state(['subtotal' => $amount, 'shipping_charge' => '0.00', 'grand_total' => $amount, 'due_total' => $amount]);
    }
}

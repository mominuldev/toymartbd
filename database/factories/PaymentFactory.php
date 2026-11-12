<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'method' => PaymentMethod::Cod,
            'gateway' => 'cod',
            'amount' => '1320.00',
            'status' => PaymentStatus::Pending,
        ];
    }
}

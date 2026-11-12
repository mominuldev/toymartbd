<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Cart\Models\Cart;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    protected $model = Cart::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'token' => (string) Str::uuid(),
            'customer_id' => null,
            'expires_at' => now()->addDays(Cart::LIFETIME_DAYS),
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    public function forCustomer(int $customerId): static
    {
        return $this->state(['customer_id' => $customerId]);
    }
}

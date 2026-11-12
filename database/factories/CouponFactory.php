<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Marketing\Enums\CouponType;
use App\Domain\Marketing\Enums\ProductScopeType;
use App\Domain\Marketing\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'TEST'.fake()->unique()->numberBetween(1000, 9_999_999),
            'description' => 'শিশু দিবসের ছাড়',
            'type' => CouponType::Percent,
            'value' => '10.00',
            'max_discount' => null,
            'min_order_amount' => '0.00',
            'usage_limit_total' => null,
            'usage_limit_per_customer' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'applies_to' => ProductScopeType::All,
            'conditions' => null,
            'excludes' => null,
            'first_order_only' => false,
            'is_active' => true,
        ];
    }

    public function fixed(string $value): static
    {
        return $this->state(['type' => CouponType::Fixed, 'value' => $value]);
    }

    public function percent(string $value): static
    {
        return $this->state(['type' => CouponType::Percent, 'value' => $value]);
    }

    public function freeShipping(): static
    {
        return $this->state(['type' => CouponType::FreeShipping, 'value' => '0.00']);
    }
}

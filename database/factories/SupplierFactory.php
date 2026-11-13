<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Purchasing\Enums\SupplierStatus;
use App\Domain\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bangla and English names side by side (CLAUDE.md rule 34).
 *
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Chittagong Toy Imports', 'Bongo Wooden Toys', 'ঢাকা খেলনা ঘর', 'Dhaka Plush House']).' '.fake()->unique()->numberBetween(1, 1_000_000),
            'contact_person' => fake()->name(),
            'phone' => '+88017'.fake()->numerify('########'),
            'payment_terms_days' => 30,
            'opening_balance' => '0.00',
            'status' => SupplierStatus::Active,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => SupplierStatus::Archived]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Marketing\Models\AdSpend;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdSpend>
 */
class AdSpendFactory extends Factory
{
    protected $model = AdSpend::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'campaign' => 'winter-toy-fest-'.fake()->unique()->numberBetween(1, 9_999_999),
            'source' => 'facebook',
            'month' => '2026-11-01',
            'amount' => '4000.00',
            'note' => 'শীতের খেলনা উৎসব',
        ];
    }
}

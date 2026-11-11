<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Enums\BrandStatus;
use App\Domain\Catalog\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    protected $model = Brand::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'name_bn' => 'ব্র্যান্ড '.fake()->unique()->numberBetween(1, 1_000_000),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 1_000_000),
            'description' => fake()->sentence(),
            'description_bn' => 'শিশুদের জন্য মানসম্পন্ন খেলনা।',
            'status' => BrandStatus::Active,
            'position' => 0,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => BrandStatus::Archived]);
    }
}

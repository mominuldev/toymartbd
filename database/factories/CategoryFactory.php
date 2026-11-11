<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Enums\CategoryStatus;
use App\Domain\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates root categories by default; use `childOf()` for a nested one. Goes
 * through Eloquent so kalnoy maintains `_lft`/`_rgt`.
 *
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'name_bn' => 'বিভাগ '.fake()->unique()->numberBetween(1, 1_000_000),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 1_000_000),
            'description' => fake()->sentence(),
            'description_bn' => 'শিশুদের জন্য খেলনা।',
            'icon' => null,
            'position' => 0,
            'is_featured' => false,
            'show_in_menu' => true,
            'status' => CategoryStatus::Active,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => CategoryStatus::Archived]);
    }

    public function hiddenFromMenu(): static
    {
        return $this->state(['show_in_menu' => false]);
    }

    public function childOf(Category $parent): static
    {
        return $this->afterMaking(function (Category $category) use ($parent): void {
            $category->appendToNode($parent);
        });
    }
}

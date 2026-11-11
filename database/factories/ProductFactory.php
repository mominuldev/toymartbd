<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A simple product with its primary category attached and exactly one default
 * variant — the shape CreateProduct produces. Use `withoutVariants()` to build
 * variants by hand. Factories bypass the Actions; tests of business rules go
 * through CreateProduct / UpdateProduct instead.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    private bool $withDefaultVariant = true;

    private string $defaultPrice = '1250.00';

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));

        return [
            'name' => $name,
            'name_bn' => 'খেলনা '.fake()->unique()->numberBetween(1, 1_000_000),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 1_000_000),
            'type' => ProductType::Simple,
            'brand_id' => null,
            'primary_category_id' => Category::factory(),
            'short_description' => fake()->sentence(),
            'short_description_bn' => 'শিশুদের জন্য মজার খেলনা।',
            'description' => fake()->paragraph(),
            'description_bn' => 'নিরাপদ ও টেকসই খেলনা।',
            'age_min_months' => 36,
            'age_max_months' => 96,
            'gender' => null,
            'is_educational' => false,
            'status' => ProductStatus::Active,
            'is_featured' => false,
            'published_at' => now()->subDay(),
        ];
    }

    /**
     * Attach the primary category and create the default variant. Done here rather
     * than in configure(): a configure() closure is bound to the first factory
     * instance and would not see withoutVariants()/priced() on a chained clone.
     *
     * @param  Collection<int, Model>  $instances
     */
    protected function callAfterCreating(Collection $instances, ?Model $parent = null): void
    {
        $instances->each(function (Model $product): void {
            if (! $product instanceof Product) {
                return;
            }

            $product->categories()->syncWithoutDetaching([$product->primary_category_id]);

            if ($this->withDefaultVariant) {
                ProductVariant::factory()->for($product)->create([
                    'sku' => 'TM-'.str_pad((string) $product->id, 5, '0', STR_PAD_LEFT),
                    'price' => $this->defaultPrice,
                    'is_default' => true,
                ]);
            }
        });

        parent::callAfterCreating($instances, $parent);
    }

    /**
     * Carry the variant settings through Laravel's immutable factory chaining.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function newInstance(array $arguments = []): static
    {
        $factory = parent::newInstance($arguments);
        $factory->withDefaultVariant = $this->withDefaultVariant;
        $factory->defaultPrice = $this->defaultPrice;

        return $factory;
    }

    public function withoutVariants(): static
    {
        $clone = clone $this;
        $clone->withDefaultVariant = false;

        return $clone;
    }

    /** The default variant's selling price, a decimal string. */
    public function priced(string $price): static
    {
        $clone = clone $this;
        $clone->defaultPrice = $price;

        return $clone;
    }

    public function variable(): static
    {
        return $this->state(['type' => ProductType::Variable])->withoutVariants();
    }

    public function draft(): static
    {
        return $this->state(['status' => ProductStatus::Draft, 'published_at' => null]);
    }

    public function archived(): static
    {
        return $this->state(['status' => ProductStatus::Archived]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reviews\Enums\ReviewStatus;
use App\Domain\Catalog\Reviews\Models\Review;
use App\Domain\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Product reviews in both languages (rule 34). Pending and unverified by default;
 * an approved factory review does not recompute the product's aggregates — use
 * ModerateReview for that.
 *
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /** @var list<array{string, string, string}> title, body, locale */
    private const array TEXTS = [
        ['Great for my son', 'He plays with it every day. Sturdy and safe, well packed.', 'en'],
        ['খুব ভালো মানের', 'আমার মেয়ে খুব পছন্দ করেছে। প্যাকেজিং ভালো ছিল, সময়মতো ডেলিভারি।', 'bn'],
        ['Good value', 'Colours are bright and the pieces fit well. Delivery took two days.', 'en'],
    ];

    /** @return array<string, mixed> */
    public function definition(): array
    {
        [$title, $body, $locale] = fake()->randomElement(self::TEXTS);

        return [
            'product_id' => Product::factory(),
            'customer_id' => Customer::factory(),
            'order_item_id' => null,
            'reviewer_name' => fake()->randomElement(['Nusrat J.', 'রহিম উ.', 'Tanvir A.']),
            'rating' => fake()->numberBetween(1, 5),
            'title' => $title,
            'body' => $body,
            'is_verified_purchase' => false,
            'status' => ReviewStatus::Pending,
            'locale' => $locale,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => ReviewStatus::Approved]);
    }

    public function rating(int $stars): static
    {
        return $this->state(['rating' => $stars]);
    }
}

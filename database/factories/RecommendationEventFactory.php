<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Enums\RecommendationEventType;
use App\Domain\Catalog\Enums\RecommendationPlacement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\RecommendationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A PDP cross-sell impression by default. Use the states for the other events.
 *
 * @extends Factory<RecommendationEvent>
 */
class RecommendationEventFactory extends Factory
{
    protected $model = RecommendationEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'placement' => RecommendationPlacement::PdpCrossSell,
            'event' => RecommendationEventType::Impression,
            'source_product_id' => Product::factory(),
            'recommended_product_id' => Product::factory(),
            'variant_id' => null,
            'session_id' => Str::random(24),
            'customer_id' => null,
            'order_id' => null,
            'occurred_at' => now(),
        ];
    }

    public function placement(RecommendationPlacement $placement): static
    {
        return $this->state(['placement' => $placement]);
    }

    public function event(RecommendationEventType $event): static
    {
        return $this->state(['event' => $event]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Marketing\Enums\PromotionType;
use App\Domain\Marketing\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Flash sale',
            'name_bn' => 'ফ্ল্যাশ সেল',
            'type' => PromotionType::FlashSale,
            'priority' => 0,
            'is_stackable' => true,
            'rules' => ['scope' => ['applies_to' => 'all'], 'type' => 'percent', 'value' => '10'],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addWeek(),
            'is_active' => true,
        ];
    }

    /** @param  array<string, mixed>  $rules */
    public function rules(PromotionType $type, array $rules): static
    {
        return $this->state(['type' => $type, 'rules' => $rules]);
    }

    public function nonStackable(): static
    {
        return $this->state(['is_stackable' => false]);
    }
}

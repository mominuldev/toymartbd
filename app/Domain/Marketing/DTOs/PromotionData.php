<?php

declare(strict_types=1);

namespace App\Domain\Marketing\DTOs;

use App\Domain\Marketing\Enums\PromotionType;
use App\Support\Money;
use DateTimeInterface;

/**
 * A promotion as an admin writes it (CreatePromotion / UpdatePromotion). `rules` is the
 * validated per-type document (docs/api/promotions.md). `flashItems` is the whole list of
 * flash-sale lines for a `flash_sale` (empty for every other type).
 */
final readonly class PromotionData
{
    /**
     * @param  array<string, mixed>  $rules
     * @param  list<array{variant_id: int, sale_price: Money, allotment: int, per_order_limit: int|null}>  $flashItems
     */
    public function __construct(
        public string $name,
        public ?string $nameBn,
        public ?string $description,
        public PromotionType $type,
        public int $priority,
        public bool $isStackable,
        public bool $allowsCoupons,
        public array $rules,
        public ?DateTimeInterface $startsAt,
        public ?DateTimeInterface $endsAt,
        public bool $isActive,
        public ?int $usageLimitTotal,
        public ?int $usageLimitPerCustomer,
        public ?Money $budget,
        public array $flashItems = [],
    ) {}

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'name_bn' => $this->nameBn,
            'description' => $this->description,
            'type' => $this->type,
            'priority' => $this->priority,
            'is_stackable' => $this->isStackable,
            'allows_coupons' => $this->allowsCoupons,
            'rules' => $this->rules,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'is_active' => $this->isActive,
            'usage_limit_total' => $this->usageLimitTotal,
            'usage_limit_per_customer' => $this->usageLimitPerCustomer,
            'budget' => $this->budget,
        ];
    }
}

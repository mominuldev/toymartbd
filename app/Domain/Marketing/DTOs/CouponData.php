<?php

declare(strict_types=1);

namespace App\Domain\Marketing\DTOs;

use App\Domain\Marketing\Enums\CouponType;
use App\Domain\Marketing\Enums\ProductScopeType;
use App\Support\Money;
use DateTimeInterface;

/** A coupon as an admin writes it (CreateCoupon / UpdateCoupon). */
final readonly class CouponData
{
    /**
     * @param  list<int>  $scopeIds
     * @param  array{product_ids?: list<int>, category_ids?: list<int>, brand_ids?: list<int>}  $excludes
     */
    public function __construct(
        public string $code,
        public ?string $description,
        public CouponType $type,
        public Money $value,
        public ?Money $maxDiscount,
        public Money $minOrderAmount,
        public ?int $usageLimitTotal,
        public ?int $usageLimitPerCustomer,
        public ?DateTimeInterface $startsAt,
        public ?DateTimeInterface $endsAt,
        public ProductScopeType $appliesTo,
        public array $scopeIds,
        public array $excludes,
        public bool $firstOrderOnly,
        public bool $isActive,
    ) {}

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'code' => $this->code,
            'description' => $this->description,
            'type' => $this->type,
            'value' => $this->value,
            'max_discount' => $this->maxDiscount,
            'min_order_amount' => $this->minOrderAmount,
            'usage_limit_total' => $this->usageLimitTotal,
            'usage_limit_per_customer' => $this->usageLimitPerCustomer,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'applies_to' => $this->appliesTo,
            'conditions' => $this->appliesTo === ProductScopeType::All ? null : ['ids' => $this->scopeIds],
            'excludes' => $this->excludes === [] ? null : $this->excludes,
            'first_order_only' => $this->firstOrderOnly,
            'is_active' => $this->isActive,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Coupon;

use App\Domain\Marketing\DTOs\CouponData;
use App\Domain\Marketing\Enums\CouponType;
use App\Domain\Marketing\Enums\ProductScopeType;
use App\Domain\Marketing\Models\Coupon;
use App\Http\Requests\ApiFormRequest;
use App\Support\DhakaDate;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The coupon document (P4-CRT-04), shared by `POST /coupons` and `PUT /coupons/{coupon}`.
 * Money is a decimal string. `scope_ids` lists the products, categories (subtrees
 * included) or brands for `applies_to`; `excludes` removes products, categories or
 * brands from any scope. `code` is upper-cased before validation. A time without an
 * offset is read as Dhaka time (P7).
 */
abstract class CouponRequest extends ApiFormRequest
{
    public const string CODE_PATTERN = '/^[A-Z0-9][A-Z0-9_-]*$/';

    public const string MONEY_PATTERN = '/^\d{1,12}(\.\d{1,2})?$/';

    abstract protected function coupon(): ?Coupon;

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');
        if (is_string($code)) {
            $this->merge(['code' => Coupon::normaliseCode($code)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['string', 'regex:'.self::MONEY_PATTERN];

        return [
            'code' => ['required', 'string', 'max:'.Coupon::CODE_MAX, 'regex:'.self::CODE_PATTERN, Rule::unique('coupons', 'code')->ignore($this->coupon()?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CouponType::class)],
            'value' => ['required', ...$money],
            'max_discount' => ['nullable', ...$money],
            'min_order_amount' => ['nullable', ...$money],
            'usage_limit_total' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_customer' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'applies_to' => ['required', Rule::enum(ProductScopeType::class)],
            'scope_ids' => ['array', 'max:500', Rule::requiredIf(fn (): bool => $this->input('applies_to') !== ProductScopeType::All->value)],
            'scope_ids.*' => ['integer', 'distinct'],
            'excludes' => ['nullable', 'array:product_ids,category_ids,brand_ids'],
            'excludes.product_ids' => ['array', 'max:500'],
            'excludes.product_ids.*' => ['integer', Rule::exists('products', 'id')],
            'excludes.category_ids' => ['array', 'max:500'],
            'excludes.category_ids.*' => ['integer', Rule::exists('categories', 'id')],
            'excludes.brand_ids' => ['array', 'max:500'],
            'excludes.brand_ids.*' => ['integer', Rule::exists('brands', 'id')],
            'first_order_only' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = CouponType::from((string) $this->input('type'));
            $value = Money::of((string) $this->input('value'));
            if ($type === CouponType::Percent && (! $value->greaterThan(Money::zero()) || $value->greaterThan(Money::of(100)))) {
                $validator->errors()->add('value', 'A percent coupon needs a value above 0 and at most 100.');
            }
            if ($type === CouponType::Fixed && ! $value->greaterThan(Money::zero())) {
                $validator->errors()->add('value', 'A fixed coupon needs a value above 0.');
            }

            $scope = ProductScopeType::from((string) $this->input('applies_to'));
            $table = match ($scope) {
                ProductScopeType::All => null,
                ProductScopeType::Products => 'products',
                ProductScopeType::Categories => 'categories',
                ProductScopeType::Brands => 'brands',
            };
            $ids = $this->scopeIds();
            if ($table !== null && DB::table($table)->whereIn('id', $ids)->count() !== count($ids)) {
                $validator->errors()->add('scope_ids', "Every scope id must be an existing {$scope->value} id.");
            }
        }];
    }

    public function toData(): CouponData
    {
        $appliesTo = ProductScopeType::from($this->string('applies_to')->toString());
        /** @var array{product_ids?: list<int>, category_ids?: list<int>, brand_ids?: list<int>} $excludes */
        $excludes = array_filter(array_map(
            static fn (mixed $ids): array => array_values(array_map('intval', is_array($ids) ? $ids : [])),
            (array) ($this->validated('excludes') ?? []),
        ));

        return new CouponData(
            code: $this->string('code')->toString(),
            description: $this->filled('description') ? $this->string('description')->toString() : null,
            type: CouponType::from($this->string('type')->toString()),
            value: Money::of($this->string('value')->toString()),
            maxDiscount: $this->filled('max_discount') ? Money::of($this->string('max_discount')->toString()) : null,
            minOrderAmount: $this->filled('min_order_amount') ? Money::of($this->string('min_order_amount')->toString()) : Money::zero(),
            usageLimitTotal: $this->filled('usage_limit_total') ? $this->integer('usage_limit_total') : null,
            usageLimitPerCustomer: $this->filled('usage_limit_per_customer') ? $this->integer('usage_limit_per_customer') : null,
            startsAt: $this->filled('starts_at') ? Carbon::parse($this->string('starts_at')->toString(), DhakaDate::zone())->utc() : null,
            endsAt: $this->filled('ends_at') ? Carbon::parse($this->string('ends_at')->toString(), DhakaDate::zone())->utc() : null,
            appliesTo: $appliesTo,
            scopeIds: $appliesTo === ProductScopeType::All ? [] : $this->scopeIds(),
            excludes: $excludes,
            firstOrderOnly: $this->boolean('first_order_only'),
            isActive: $this->has('is_active') ? $this->boolean('is_active') : true,
        );
    }

    /** @return list<int> */
    private function scopeIds(): array
    {
        $ids = $this->input('scope_ids');

        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }
}

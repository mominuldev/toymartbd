<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Promotion;

use App\Domain\Marketing\DTOs\PromotionData;
use App\Domain\Marketing\Enums\ProductScopeType;
use App\Domain\Marketing\Enums\PromotionType;
use App\Http\Requests\ApiFormRequest;
use App\Support\DhakaDate;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The promotion document (P7, docs/api/promotions.md), shared by `POST /promotions` and
 * `PUT /promotions/{promotion}`. Money is a decimal string. Times without an offset are
 * read as Dhaka time. `rules` holds the per-type terms and the scope; `flash_items` the
 * flash-sale lines. A `flash_sale` needs both `starts_at` and `ends_at` (a hard window).
 */
abstract class PromotionRequest extends ApiFormRequest
{
    public const string MONEY_PATTERN = '/^\d{1,12}(\.\d{1,2})?$/';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['string', 'regex:'.self::MONEY_PATTERN];
        $type = PromotionType::tryFrom((string) $this->input('type'));

        return [
            'name' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::enum(PromotionType::class)],
            'priority' => ['sometimes', 'integer', 'between:-1000,1000'],
            'is_stackable' => ['sometimes', 'boolean'],
            'allows_coupons' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date', Rule::requiredIf($type === PromotionType::FlashSale)],
            'ends_at' => ['nullable', 'date', 'after:starts_at', Rule::requiredIf($type === PromotionType::FlashSale)],
            'is_active' => ['sometimes', 'boolean'],
            'usage_limit_total' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_customer' => ['nullable', 'integer', 'min:1'],
            'budget' => ['nullable', ...$money],

            'rules' => ['present', 'array'],
            'rules.scope' => ['sometimes', 'array:applies_to,ids,exclude_product_ids,exclude_category_ids,exclude_brand_ids'],
            'rules.scope.applies_to' => ['sometimes', Rule::enum(ProductScopeType::class)],
            'rules.scope.ids' => ['array', 'max:500'],
            'rules.scope.ids.*' => ['integer', 'distinct'],
            'rules.scope.exclude_product_ids' => ['array', 'max:500'],
            'rules.scope.exclude_product_ids.*' => ['integer', Rule::exists('products', 'id')],
            'rules.scope.exclude_category_ids' => ['array', 'max:500'],
            'rules.scope.exclude_category_ids.*' => ['integer', Rule::exists('categories', 'id')],
            'rules.scope.exclude_brand_ids' => ['array', 'max:500'],
            'rules.scope.exclude_brand_ids.*' => ['integer', Rule::exists('brands', 'id')],
            'rules.type' => ['nullable', Rule::in(['percent', 'fixed', 'price'])],
            'rules.value' => ['nullable', ...$money],
            'rules.max_discount' => ['nullable', ...$money],
            'rules.tiers' => ['sometimes', 'array', 'max:10'],
            'rules.tiers.*.min_subtotal' => ['required', ...$money],
            'rules.tiers.*.type' => ['required', Rule::in(['percent', 'fixed'])],
            'rules.tiers.*.value' => ['required', ...$money],
            'rules.buy' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'rules.get' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'rules.percent' => ['sometimes', 'numeric', 'gt:0', 'max:100'],
            'rules.product_ids' => ['sometimes', 'array', 'max:20'],
            'rules.product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')],
            'rules.min_subtotal' => ['sometimes', ...$money],

            'flash_items' => ['sometimes', 'array', 'max:200'],
            'flash_items.*.variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'flash_items.*.sale_price' => ['required', ...$money],
            'flash_items.*.allotment' => ['required', 'integer', 'min:1', 'max:100000'],
            'flash_items.*.per_order_limit' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $type = PromotionType::from((string) $this->input('type'));
            $rules = (array) $this->input('rules', []);
            $kind = $rules['type'] ?? null;
            $value = isset($rules['value']) ? Money::of((string) $rules['value']) : null;
            $err = static fn (string $field, string $message) => $validator->errors()->add($field, $message);

            $needsDiscount = in_array($type, [PromotionType::Discount, PromotionType::CategoryDiscount], true)
                || ($type === PromotionType::FlashSale && $this->input('flash_items', []) === []);
            if ($needsDiscount) {
                if (! in_array($kind, ['percent', 'fixed'], true)) {
                    $err('rules.type', 'Choose percent or fixed.');
                } elseif ($value === null || ! $value->greaterThan(Money::zero()) || ($kind === 'percent' && $value->greaterThan(Money::of(100)))) {
                    $err('rules.value', 'A percent needs a value above 0 and at most 100; a fixed amount a value above 0.');
                }
            }
            if ($type === PromotionType::Tiered && ($rules['tiers'] ?? []) === []) {
                $err('rules.tiers', 'Add at least one tier.');
            }
            if ($type === PromotionType::BuyXGetY && (! isset($rules['buy']) || ! isset($rules['get']))) {
                $err('rules.buy', 'Buy and get quantities are required.');
            }
            if ($type === PromotionType::Bundle) {
                if (count($rules['product_ids'] ?? []) < 2) {
                    $err('rules.product_ids', 'A bundle needs at least two products.');
                }
                if (! in_array($kind, ['percent', 'fixed', 'price'], true) || $value === null || ! $value->greaterThan(Money::zero())) {
                    $err('rules.value', 'Choose percent, fixed or bundle price, with a value above 0.');
                }
            }
            if ($type === PromotionType::FreeDelivery && ! isset($rules['min_subtotal'])) {
                $err('rules.min_subtotal', 'Set the spend that unlocks free delivery.');
            }
            if ($type !== PromotionType::FlashSale && $this->input('flash_items', []) !== []) {
                $err('flash_items', 'Only a flash sale has flash-sale items.');
            }

            foreach ((array) $this->input('flash_items', []) as $i => $item) {
                $price = DB::table('product_variants')->where('id', (int) $item['variant_id'])->value('price');
                if ($price !== null && ! Money::of((string) $item['sale_price'])->lessThan(Money::of((string) $price))) {
                    $err("flash_items.{$i}.sale_price", 'The sale price must be below the regular price ('.Money::of((string) $price)->toDecimal().').');
                }
            }

            $scope = ProductScopeType::tryFrom((string) ($rules['scope']['applies_to'] ?? 'all')) ?? ProductScopeType::All;
            $table = match ($scope) {
                ProductScopeType::All => null,
                ProductScopeType::Products => 'products',
                ProductScopeType::Categories => 'categories',
                ProductScopeType::Brands => 'brands',
            };
            $ids = array_values(array_unique(array_map('intval', (array) ($rules['scope']['ids'] ?? []))));
            if ($table !== null && ($ids === [] || DB::table($table)->whereIn('id', $ids)->count() !== count($ids))) {
                $err('rules.scope.ids', "Choose at least one existing {$scope->value} id.");
            }
        }];
    }

    public function toData(): PromotionData
    {
        $type = PromotionType::from($this->string('type')->toString());

        return new PromotionData(
            name: $this->string('name')->toString(),
            nameBn: $this->filled('name_bn') ? $this->string('name_bn')->toString() : null,
            description: $this->filled('description') ? $this->string('description')->toString() : null,
            type: $type,
            priority: $this->integer('priority'),
            isStackable: $this->has('is_stackable') ? $this->boolean('is_stackable') : true,
            allowsCoupons: $this->has('allows_coupons') ? $this->boolean('allows_coupons') : true,
            rules: self::normaliseRules($type, (array) $this->validated('rules')),
            startsAt: $this->filled('starts_at') ? Carbon::parse($this->string('starts_at')->toString(), DhakaDate::zone())->utc() : null,
            endsAt: $this->filled('ends_at') ? Carbon::parse($this->string('ends_at')->toString(), DhakaDate::zone())->utc() : null,
            isActive: $this->has('is_active') ? $this->boolean('is_active') : true,
            usageLimitTotal: $this->filled('usage_limit_total') ? $this->integer('usage_limit_total') : null,
            usageLimitPerCustomer: $this->filled('usage_limit_per_customer') ? $this->integer('usage_limit_per_customer') : null,
            budget: $this->filled('budget') ? Money::of($this->string('budget')->toString()) : null,
            flashItems: array_values(array_map(static fn (array $i): array => [
                'variant_id' => (int) $i['variant_id'],
                'sale_price' => Money::of((string) $i['sale_price']),
                'allotment' => (int) $i['allotment'],
                'per_order_limit' => isset($i['per_order_limit']) ? (int) $i['per_order_limit'] : null,
            ], (array) ($this->validated('flash_items') ?? []))),
        );
    }

    /**
     * Keep only the keys the type reads, money as normalised decimal strings.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private static function normaliseRules(PromotionType $type, array $rules): array
    {
        $money = static fn (mixed $v): ?string => $v === null || $v === '' ? null : Money::of((string) $v)->toDecimal();
        $ints = static fn (mixed $v): array => array_values(array_unique(array_map('intval', is_array($v) ? $v : [])));
        $scope = (array) ($rules['scope'] ?? []);
        $appliesTo = (string) ($scope['applies_to'] ?? 'all');
        $out = ['scope' => array_filter([
            'applies_to' => $appliesTo,
            'ids' => $appliesTo === 'all' ? [] : $ints($scope['ids'] ?? []),
            'exclude_product_ids' => $ints($scope['exclude_product_ids'] ?? []),
            'exclude_category_ids' => $ints($scope['exclude_category_ids'] ?? []),
            'exclude_brand_ids' => $ints($scope['exclude_brand_ids'] ?? []),
        ], static fn (mixed $v): bool => $v !== [])];

        return $out + array_filter(match ($type) {
            PromotionType::Discount, PromotionType::CategoryDiscount, PromotionType::FlashSale => [
                'type' => $rules['type'] ?? null, 'value' => $money($rules['value'] ?? null), 'max_discount' => $money($rules['max_discount'] ?? null),
            ],
            PromotionType::Tiered => [
                'tiers' => array_values(array_map(static fn (array $t): array => [
                    'min_subtotal' => $money($t['min_subtotal']), 'type' => $t['type'], 'value' => $money($t['value']),
                ], (array) ($rules['tiers'] ?? []))),
                'max_discount' => $money($rules['max_discount'] ?? null),
            ],
            PromotionType::BuyXGetY => [
                'buy' => (int) ($rules['buy'] ?? 0), 'get' => (int) ($rules['get'] ?? 0),
                'percent' => isset($rules['percent']) ? (string) $rules['percent'] : '100', 'max_discount' => $money($rules['max_discount'] ?? null),
            ],
            PromotionType::Bundle => [
                'product_ids' => $ints($rules['product_ids'] ?? []), 'type' => $rules['type'] ?? null, 'value' => $money($rules['value'] ?? null),
            ],
            PromotionType::FreeDelivery => ['min_subtotal' => $money($rules['min_subtotal'] ?? '0')],
        }, static fn (mixed $v): bool => $v !== null);
    }
}

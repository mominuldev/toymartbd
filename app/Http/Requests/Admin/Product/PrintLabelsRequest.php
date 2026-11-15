<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\Enums\LabelTemplate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /products/labels` — barcode labels as one PDF. Needs catalog.view; with
 * `generate_missing: true` also catalog.update (it writes barcodes first), else 403.
 *
 * `items[] {variant_id, copies}` — ≤ 300 items, copies 1–500, ≤ 5,000 labels in all; printed
 * in the order given. A variant without a barcode is a 422 on `items.N.variant_id` unless
 * `generate_missing` is true. Only the fields below are accepted; any other is a 422.
 */
final class PrintLabelsRequest extends ApiFormRequest
{
    public const int MAX_ITEMS = 300;

    public const int MAX_COPIES = 500;

    public const int MAX_LABELS = 5000;

    private const array FIELDS = ['items', 'template', 'show_price', 'show_name', 'locale', 'generate_missing'];

    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->can('viewAny', Product::class)) {
            return false;
        }

        return ! $this->boolean('generate_missing') || $user->can(Permission::CatalogUpdate->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*' => ['required', 'array:variant_id,copies'],
            'items.*.variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'items.*.copies' => ['required', 'integer', 'min:1', 'max:'.self::MAX_COPIES],
            'template' => ['required', 'string', Rule::enum(LabelTemplate::class)],
            /** Print the selling price (default true). */
            'show_price' => ['sometimes', 'boolean'],
            /** Print the product and variant name (default true). */
            'show_name' => ['sometimes', 'boolean'],
            /** Language of the printed name, falling back to English (default `en`). */
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(['en', 'bn'])],
            /** Generate in-store EAN-13s for variants without a barcode first; needs catalog.update. */
            'generate_missing' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_keys($this->all()) as $key) {
                    if (! in_array($key, self::FIELDS, true)) {
                        $validator->errors()->add((string) $key, 'This field is not accepted.');
                    }
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $items = $this->items();
                if (array_sum(array_column($items, 'copies')) > self::MAX_LABELS) {
                    $validator->errors()->add('items', 'At most '.self::MAX_LABELS.' labels can be printed at once.');
                }

                if ($this->boolean('generate_missing')) {
                    return;
                }

                $missing = ProductVariant::query()->whereKey(array_column($items, 'variant_id'))->whereNull('barcode')->pluck('id')->flip();
                foreach ($items as $i => $item) {
                    if ($missing->has($item['variant_id'])) {
                        $validator->errors()->add("items.{$i}.variant_id", 'This variant has no barcode. Generate one first.');
                    }
                }
            },
        ];
    }

    /** @return list<array{variant_id: int, copies: int}> */
    public function items(): array
    {
        /** @var list<array{variant_id: int|string, copies: int|string}> $items */
        $items = array_values((array) $this->input('items', []));

        return array_map(static fn (array $item): array => ['variant_id' => (int) $item['variant_id'], 'copies' => (int) $item['copies']], $items);
    }

    public function template(): LabelTemplate
    {
        return LabelTemplate::from($this->string('template')->toString());
    }

    public function showPrice(): bool
    {
        return $this->boolean('show_price', true);
    }

    public function showName(): bool
    {
        return $this->boolean('show_name', true);
    }

    public function labelLocale(): string
    {
        return $this->input('locale') === 'bn' ? 'bn' : 'en';
    }

    public function generateMissing(): bool
    {
        return $this->boolean('generate_missing');
    }
}

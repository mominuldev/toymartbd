<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\Enums\LabelTemplate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Invoicing\Services\PrintSettings;
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
 *
 * Every field but `items` and `locale` is optional and, when omitted, takes the value saved in
 * Settings › Invoices, receipts & labels › Barcode labels ({@see PrintSettings::labels()}, the
 * Figma defaults until saved). The saved "create missing barcodes" applies only for someone
 * with catalog.update; for anyone else an omitted `generate_missing` is false, so a variant
 * without a barcode is the usual 422 rather than a 403.
 */
final class PrintLabelsRequest extends ApiFormRequest
{
    public const int MAX_ITEMS = 300;

    public const int MAX_COPIES = 500;

    public const int MAX_LABELS = 5000;

    private const array FIELDS = ['items', 'template', 'show_price', 'show_name', 'show_options', 'show_store', 'show_digits', 'locale', 'generate_missing'];

    /** @var array<string, string|bool|null>|null */
    private ?array $settings = null;

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
            /** The label stock; omitted, the saved Barcode labels paper. */
            'template' => ['sometimes', 'string', Rule::enum(LabelTemplate::class)],
            /** Print the selling price; omitted, the saved setting. */
            'show_price' => ['sometimes', 'boolean'],
            /** Print the product name; omitted, the saved setting. */
            'show_name' => ['sometimes', 'boolean'],
            /** Print the variant's options line (size, colour); omitted, the saved setting. */
            'show_options' => ['sometimes', 'boolean'],
            /** Print the store name (in the label language) at the top; omitted, the saved setting. */
            'show_store' => ['sometimes', 'boolean'],
            /** Print the barcode number under the bars; omitted, the saved setting. */
            'show_digits' => ['sometimes', 'boolean'],
            /** Language of the printed name, falling back to English (default `en`). */
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(['en', 'bn'])],
            /**
             * Generate in-store EAN-13s for variants without a barcode first; `true` needs
             * catalog.update. Omitted, the saved setting for someone with catalog.update, else false.
             */
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

                if ($this->generateMissing()) {
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
        $value = $this->has('template') ? $this->string('template')->toString() : $this->saved('template');

        return LabelTemplate::tryFrom(is_string($value) ? $value : '') ?? LabelTemplate::Roll50x25;
    }

    public function showPrice(): bool
    {
        return $this->flag('show_price');
    }

    public function showName(): bool
    {
        return $this->flag('show_name');
    }

    public function showOptions(): bool
    {
        return $this->flag('show_options');
    }

    public function showStore(): bool
    {
        return $this->flag('show_store');
    }

    public function showDigits(): bool
    {
        return $this->flag('show_digits');
    }

    public function labelLocale(): string
    {
        return $this->input('locale') === 'bn' ? 'bn' : 'en';
    }

    public function generateMissing(): bool
    {
        if ($this->has('generate_missing')) {
            return $this->boolean('generate_missing');
        }

        return $this->saved('generate_missing') === true && $this->user()?->can(Permission::CatalogUpdate->value) === true;
    }

    /** The field as sent, else the saved Barcode labels setting. */
    private function flag(string $field): bool
    {
        return $this->has($field) ? $this->boolean($field) : $this->saved($field) === true;
    }

    private function saved(string $field): string|bool|null
    {
        $this->settings ??= app(PrintSettings::class)->labels();

        return $this->settings[$field] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\Models\Product;
use App\Http\Requests\ApiFormRequest;

/**
 * `PATCH /products/{product}/images/{image}` — any of `alt_text`, `alt_text_bn`,
 * `variant_id` (null detaches), `is_primary` (`true` only). Needs catalog.update.
 */
final class UpdateProductImageRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product && ($this->user()?->can('update', $product) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alt_text_bn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'variant_id' => ['sometimes', 'nullable', 'integer'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{alt_text?: string|null, alt_text_bn?: string|null, variant_id?: int|null, is_primary?: bool} */
    public function changes(): array
    {
        $out = [];

        foreach (['alt_text', 'alt_text_bn'] as $key) {
            if ($this->has($key)) {
                $value = $this->input($key);
                $out[$key] = is_string($value) ? $value : null;
            }
        }

        if ($this->has('variant_id')) {
            $out['variant_id'] = $this->input('variant_id') === null ? null : $this->integer('variant_id');
        }

        if ($this->has('is_primary')) {
            $out['is_primary'] = $this->boolean('is_primary');
        }

        return $out;
    }
}

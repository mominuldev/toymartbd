<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\Models\Product;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /products/seo-measure` — the server's pixel measurement of an unsaved meta
 * title and description (the SeoPanel meters). Read-only; needs catalog.view.
 */
final class SeoMeasureRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Product::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function text(string $key): string
    {
        $value = $this->validated($key);

        return is_string($value) ? trim($value) : '';
    }
}

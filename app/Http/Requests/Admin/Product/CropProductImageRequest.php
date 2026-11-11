<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\Models\Product;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /products/{product}/images/{image}/crop` — `{ x, y, width, height }` in the
 * image's current pixels (`width` × `height` on the resource). Needs catalog.update.
 */
final class CropProductImageRequest extends ApiFormRequest
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
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
            'width' => ['required', 'integer', 'min:1'],
            'height' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array{x: int, y: int, width: int, height: int} */
    public function rectangle(): array
    {
        return [
            'x' => $this->integer('x'),
            'y' => $this->integer('y'),
            'width' => $this->integer('width'),
            'height' => $this->integer('height'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\Models\Product;
use App\Http\Requests\ApiFormRequest;
use App\Http\Rules\RealImage;
use Illuminate\Http\UploadedFile;

/**
 * `POST /products/{product}/images` — `multipart/form-data`: `file` (JPEG, PNG, WebP
 * or AVIF by content, ≤ 10 MB, ≤ 8000 px per side), optional `alt_text`,
 * `alt_text_bn`, `variant_id`, and an optional `crop[x|y|width|height]` rectangle
 * in the source image's (EXIF-oriented) pixels. Needs catalog.update.
 */
final class StoreProductImageRequest extends ApiFormRequest
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
            'file' => ['required', 'file', 'max:10240', new RealImage],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'alt_text_bn' => ['nullable', 'string', 'max:255'],
            'variant_id' => ['nullable', 'integer'],
            'crop' => ['nullable', 'array:x,y,width,height'],
            'crop.x' => ['required_with:crop', 'integer', 'min:0'],
            'crop.y' => ['required_with:crop', 'integer', 'min:0'],
            'crop.width' => ['required_with:crop', 'integer', 'min:1'],
            'crop.height' => ['required_with:crop', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{alt_text?: string|null, alt_text_bn?: string|null, variant_id?: int|null, crop?: array{x: int, y: int, width: int, height: int}|null}
     */
    public function imageAttributes(): array
    {
        $out = [];

        foreach (['alt_text', 'alt_text_bn'] as $key) {
            if ($this->has($key)) {
                $value = $this->input($key);
                $out[$key] = is_string($value) ? $value : null;
            }
        }

        if ($this->filled('variant_id')) {
            $out['variant_id'] = $this->integer('variant_id');
        }

        $crop = $this->input('crop');

        if (is_array($crop)) {
            $out['crop'] = [
                'x' => (int) $crop['x'],
                'y' => (int) $crop['y'],
                'width' => (int) $crop['width'],
                'height' => (int) $crop['height'],
            ];
        }

        return $out;
    }

    public function uploadedPath(): string
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? ($file->getRealPath() ?: $file->getPathname()) : '';
    }
}

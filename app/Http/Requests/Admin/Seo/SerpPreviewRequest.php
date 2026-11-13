<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Seo;

use App\Domain\Seo\Models\SeoMeta;
use App\Http\Requests\ApiFormRequest;

/** `POST /seo/serp-preview` — the server's truncation of unsaved title/description. Read-only. */
final class SerpPreviewRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', SeoMeta::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function text(string $key): string
    {
        $value = $this->validated($key);

        return is_string($value) ? trim($value) : '';
    }
}

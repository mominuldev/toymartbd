<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Seo;

use App\Domain\Seo\Models\SeoMeta;
use App\Http\Requests\ApiFormRequest;

/** `POST /seo/sitemap/regenerate` — no body; seo.manage. */
final class RegenerateSitemapRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SeoMeta::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}

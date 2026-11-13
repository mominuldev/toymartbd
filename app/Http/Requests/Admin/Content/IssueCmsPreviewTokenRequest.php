<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Content;

use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/** `POST /content/home/preview-token` — `locale` en|bn picks the storefront prefix of the preview URL. */
final class IssueCmsPreviewTokenRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', CmsBlock::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['locale' => ['sometimes', Rule::in(['en', 'bn'])]];
    }

    public function previewLocale(): string
    {
        return $this->input('locale') === 'bn' ? 'bn' : 'en';
    }
}

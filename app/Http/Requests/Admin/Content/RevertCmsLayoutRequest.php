<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Content;

use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /content/home/versions/{version}/revert` — `publish` (default true) puts the old
 * version live again as a new version; false only restores it into the draft.
 */
final class RevertCmsLayoutRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CmsLayoutVersion::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['publish' => ['sometimes', 'boolean']];
    }

    public function publish(): bool
    {
        return $this->boolean('publish', true);
    }
}

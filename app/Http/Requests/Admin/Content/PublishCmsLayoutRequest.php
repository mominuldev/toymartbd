<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Content;

use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Http\Requests\ApiFormRequest;

/** `POST /content/home/publish` — an optional note for the version history. */
final class PublishCmsLayoutRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CmsLayoutVersion::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['sometimes', 'nullable', 'string', 'max:191']];
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Media;

use App\Domain\Catalog\Media\Models\MediaFolder;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /media/folders` and `PATCH /media/folders/{folder}` — `{ name }`, 1–60
 * characters after trimming. Uniqueness (case-insensitive) is checked by the Action
 * under a lock. Needs media.manage.
 */
final class MediaFolderRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $folder = $this->route('folder');

        return $folder instanceof MediaFolder
            ? ($this->user()?->can('update', $folder) ?? false)
            : ($this->user()?->can('create', MediaFolder::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'min:1', 'max:60']];
    }

    public function folderName(): string
    {
        return trim($this->string('name')->toString());
    }
}

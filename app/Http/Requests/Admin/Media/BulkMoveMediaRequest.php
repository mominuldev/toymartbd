<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Media;

use App\Domain\Catalog\Media\Models\Media;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /media/bulk/move` — `{ ids, folder_id }`: 1–200 distinct existing media ids and
 * a folder id, or null to un-file. Needs media.manage.
 */
final class BulkMoveMediaRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateAny', Media::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct', 'exists:media,id'],
            'folder_id' => ['present', 'nullable', 'integer', 'exists:media_folders,id'],
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        /** @var array<int, int|string> $ids */
        $ids = $this->input('ids', []);

        return array_values(array_map(intval(...), $ids));
    }

    public function folderId(): ?int
    {
        return $this->input('folder_id') === null ? null : $this->integer('folder_id');
    }
}

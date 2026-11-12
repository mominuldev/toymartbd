<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Media;

use App\Domain\Catalog\Media\Models\Media;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Http\UploadedFile;

/**
 * `POST /media/{media}/replace` — `multipart/form-data`, `file` of the same kind as the
 * media (image ↔ image, PDF ↔ PDF), ≤ 10 MB, checked by content. Needs media.manage.
 */
final class ReplaceMediaRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $media = $this->route('media');

        return $media instanceof Media && ($this->user()?->can('update', $media) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', new MediaFileRule]];
    }

    public function uploadedPath(): string
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? ($file->getRealPath() ?: $file->getPathname()) : '';
    }

    public function clientName(): string
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file->getClientOriginalName() : 'file';
    }
}

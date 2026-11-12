<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Media;

use App\Domain\Catalog\Media\Models\Media;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Http\UploadedFile;

/**
 * `POST /media` — `multipart/form-data`, one file: an image (JPEG, PNG, WebP, AVIF) or a
 * PDF, by content, ≤ 10 MB; optional `folder_id`, `alt_text`, `alt_text_bn`, `caption`.
 * Needs media.manage.
 */
final class StoreMediaRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Media::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', new MediaFileRule],
            'folder_id' => ['nullable', 'integer', 'exists:media_folders,id'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'alt_text_bn' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array{folder_id?: int|null, alt_text?: string|null, alt_text_bn?: string|null, caption?: string|null} */
    public function attributesForUpload(): array
    {
        $out = ['folder_id' => $this->filled('folder_id') ? $this->integer('folder_id') : null];

        foreach (['alt_text', 'alt_text_bn', 'caption'] as $key) {
            $value = $this->input($key);
            $out[$key] = is_string($value) ? $value : null;
        }

        return $out;
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

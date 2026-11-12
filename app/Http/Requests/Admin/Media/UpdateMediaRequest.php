<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Media;

use App\Domain\Catalog\Media\Models\Media;
use App\Http\Requests\ApiFormRequest;

/**
 * `PATCH /media/{media}` — any of `alt_text`, `alt_text_bn` (≤ 255), `caption` (≤ 500),
 * `folder_id` (a folder id or null). Only the keys present change. Needs media.manage.
 */
final class UpdateMediaRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $media = $this->route('media');

        return $media instanceof Media && ($this->user()?->can('update', $media) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alt_text_bn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:500'],
            'folder_id' => ['sometimes', 'nullable', 'integer', 'exists:media_folders,id'],
        ];
    }

    /** @return array{alt_text?: string|null, alt_text_bn?: string|null, caption?: string|null, folder_id?: int|null} */
    public function changes(): array
    {
        $out = [];

        foreach (['alt_text', 'alt_text_bn', 'caption'] as $key) {
            if ($this->exists($key)) {
                $value = $this->input($key);
                $out[$key] = is_string($value) ? $value : null;
            }
        }

        if ($this->exists('folder_id')) {
            $out['folder_id'] = $this->input('folder_id') === null ? null : $this->integer('folder_id');
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * `POST /auth/avatar` — multipart `{ avatar }` (JPG, PNG, WebP or AVIF up to 5 MB), or `{ media_id }`
 * to use an image already in the media library. Your own photo only.
 */
final class UpdateOwnAvatarRequest extends ApiFormRequest
{
    public const int MAX_KB = 5120;

    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'avatar' => ['required_without:media_id', 'prohibits:media_id', 'file', 'mimes:jpg,jpeg,png,webp,avif', 'max:'.self::MAX_KB],
            'media_id' => ['nullable', 'integer', 'exists:media,id'],
        ];
    }

    public function avatarPath(): ?string
    {
        $file = $this->file('avatar');

        return $file instanceof UploadedFile ? (string) $file->getRealPath() : null;
    }

    public function mediaId(): ?int
    {
        return $this->filled('media_id') ? $this->integer('media_id') : null;
    }
}

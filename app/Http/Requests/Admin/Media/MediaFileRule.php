<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Media;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * The library's size check before the pipeline runs, with the message the upload queue
 * shows ("11.4 MB — over the 10 MB limit."). The type and content checks happen in
 * MediaFiles, by content.
 */
final class MediaFileRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $limit = config('media.max_bytes');
        $limit = is_int($limit) ? $limit : 10 * 1048576;
        $size = (int) $value->getSize();

        if ($size > $limit) {
            $fail((string) __('media.file.too_large', [
                'size' => sprintf('%.1f', $size / 1048576),
                'limit' => intdiv($limit, 1048576),
            ]));
        }
    }
}

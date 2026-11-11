<?php

declare(strict_types=1);

namespace App\Http\Rules;

use App\Domain\Catalog\Exceptions\InvalidImage;
use App\Domain\Catalog\Services\ImagePipeline;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * The upload is a JPEG, PNG, WebP or AVIF by its content (magic bytes + finfo),
 * within the size and dimension limits, and carries no script payload. The
 * extension and the client-sent MIME type are ignored (P4-CAT-07).
 */
final class RealImage implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The upload failed; try again.');

            return;
        }

        try {
            app(ImagePipeline::class)->inspect($value->getRealPath() ?: $value->getPathname());
        } catch (InvalidImage $e) {
            $fail($e->getMessage());
        }
    }
}

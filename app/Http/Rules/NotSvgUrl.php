<?php

declare(strict_types=1);

namespace App\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A social share image URL must not be an SVG: Facebook, WhatsApp, Messenger and X render
 * no SVG preview. Library SVGs are served at `/m/{id}/{name}.svg`, so the path says it.
 */
final class NotSvgUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $path = parse_url($value, PHP_URL_PATH);

        if (is_string($path) && str_ends_with(strtolower($path), '.svg')) {
            $fail((string) __('media.og_svg'));
        }
    }
}

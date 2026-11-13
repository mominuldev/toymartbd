<?php

declare(strict_types=1);

namespace App\Domain\Seo\Subjects;

use App\Domain\Catalog\Services\ProductDescription;

/** Plain-text meta descriptions from rich-text content (cut at a word, ≤ 155 chars). */
final class ContentText
{
    public const int CHARS = 155;

    public static function summary(?string $html): ?string
    {
        $text = ProductDescription::plainText($html);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) <= self::CHARS) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::CHARS);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > 80 ? mb_substr($cut, 0, $space) : $cut, ' ,.;:।').'…';
    }
}

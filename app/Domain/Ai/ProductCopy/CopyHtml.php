<?php

declare(strict_types=1);

namespace App\Domain\Ai\ProductCopy;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The HTML an AI description may carry: `<p>`, `<ul>`, `<li>`, `<strong>` — nothing else, no
 * attributes. Anything outside is unwrapped to its text (or dropped, for script-like elements).
 * Narrower than the product description's own allowlist, which re-sanitises it on Save anyway.
 */
final class CopyHtml
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function clean(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|template|noscript|textarea|xmp|iframe)\b[^>]*>.*?(</\1\s*>|$)#is', '', $html);
        $clean = self::sanitizer()->sanitize($html);
        $clean = str_replace(['&#43;', '&#61;', '&#64;', '&#96;'], ['+', '=', '@', '`'], $clean);
        // Empty blocks the model sometimes leaves between sections.
        $clean = (string) preg_replace('#<(p|li|strong)>\s*</\1>#', '', $clean);
        $clean = (string) preg_replace('#<ul>\s*</ul>#', '', $clean);

        return trim($clean);
    }

    /** Readable text of the HTML, for length and figure checks. */
    public static function text(string $html): string
    {
        $text = (string) preg_replace('#<\s*(?:/p|/li|/ul)\b[^>]*>#i', ' $0', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function sanitizer(): HtmlSanitizer
    {
        return self::$sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->defaultAction(HtmlSanitizerAction::Block)
                ->allowElement('p')
                ->allowElement('ul')
                ->allowElement('li')
                ->allowElement('strong')
                ->dropElement('script')
                ->dropElement('style')
                ->withMaxInputLength(200_000),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The long product description (`products.description` / `description_bn`) is rich text,
 * stored as HTML written by the admin's RichTextToolbar editor (Figma 201:1570). Everything
 * that reaches the column passes through {@see sanitize()}, an allowlist:
 *
 * - p, h2, h3, strong, em, ul, ol, li, blockquote, br — no attributes;
 * - a[href] with http, https or mailto, or a site-relative link — root-relative (`/pages/x`,
 *   `/bn/blog/y`) or an in-page `#anchor`; javascript:, data:, vbscript: and every other scheme,
 *   protocol-relative `//host` tricks and bare relative paths (`foo/bar`) lose their href.
 *   An external web link gets rel="noopener nofollow";
 * - img[src, alt, width, height] only from our own media: the stable `/m/…` URL (relative or on
 *   the app host) or the public media disk / CDN base (config/media.php).
 *
 * Every other element is unwrapped (its text kept); script, style, iframe and the other
 * active-content elements are dropped with their content. Text written before the editor
 * existed (no tags at all) is read as paragraphs split on blank lines.
 *
 * Search and SEO want words, not markup: {@see plainText()}.
 */
final class ProductDescription
{
    /** Hosts of the storefront itself: links there are not "external". */
    private const array SITE_HOSTS = ['toymartbd.com', 'www.toymartbd.com'];

    /** Elements removed together with everything inside them. */
    private const array DROPPED = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'template',
        'svg', 'math', 'form', 'input', 'button', 'select', 'textarea', 'option', 'head', 'title', 'meta', 'link',
        'base', 'video', 'audio', 'source', 'track', 'canvas', 'picture',
    ];

    /** Block-level boundaries, for turning HTML into readable plain text. */
    private const string BLOCK_BOUNDARY = '#<\s*(?:br|/p|/h[1-6]|/li|/blockquote|/ul|/ol|/div)\b[^>]*>#i';

    private static ?HtmlSanitizer $sanitizer = null;

    /** Sanitised HTML, or null when nothing readable is left. */
    public static function sanitize(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        $html = self::isHtml($input) ? $input : self::fromPlainText($input);
        // Raw-text elements first: the HTML parser can hoist a <style> out of the fragment and
        // hand its rules back as text, so their content is removed before parsing.
        $html = (string) preg_replace(['#<(script|style|template|noscript|textarea|xmp|iframe)\b[^>]*>.*?</\1\s*>#is', '#<(script|style|template|noscript|textarea|xmp|iframe)\b[^>]*>.*$#is'], '', $html);
        $clean = self::sanitizer()->sanitize($html);
        // The sanitiser encodes + = @ ` for legacy-IE safety; they are harmless in text and in
        // double-quoted attributes, and Bangla copy like "বয়স ৪+" should store as written.
        $clean = str_replace(['&#43;', '&#61;', '&#64;', '&#96;'], ['+', '=', '@', '`'], $clean);
        $clean = self::filterImages($clean);
        $clean = self::markExternalLinks($clean);
        $clean = trim($clean);

        return self::isBlank($clean) ? null : $clean;
    }

    /**
     * What a reader gets back: stored HTML as is (it was sanitised on save), legacy plain
     * text as paragraphs. Re-sanitised either way, so a row written around the editor (a
     * seeder, an import) can never reach a page unfiltered.
     */
    public static function html(?string $stored): ?string
    {
        return self::sanitize($stored);
    }

    /** The description as one line of text: tags removed, blocks separated by spaces, entities decoded. */
    public static function plainText(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        $text = self::isHtml($stored) ? (string) preg_replace(self::BLOCK_BOUNDARY, ' $0', $stored) : $stored;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Any opening or closing tag means the text is HTML; "3 < 5" is not. */
    public static function isHtml(string $text): bool
    {
        return preg_match('#</?[a-z][a-z0-9]*(?:\s[^>]*)?/?>#i', $text) === 1;
    }

    /** Plain text → paragraphs on blank lines; a single line break stays a <br>. */
    public static function fromPlainText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $paragraphs = preg_split('/\n[ \t]*\n+/', trim($text)) ?: [];

        return implode('', array_map(
            static fn (string $p): string => '<p>'.implode('<br>', array_map(
                static fn (string $line): string => htmlspecialchars(trim($line), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                explode("\n", trim($p)),
            )).'</p>',
            array_filter($paragraphs, static fn (string $p): bool => trim($p) !== ''),
        ));
    }

    private static function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer !== null) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowElement('p')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('br')
            ->allowElement('a', ['href'])
            ->allowElement('img', ['src', 'alt', 'width', 'height'])
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            // Relative hrefs pass the sanitiser; markExternalLinks() keeps only `/…` and `#…`.
            ->allowRelativeLinks(true)
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias()
            // The request allows 50,000 characters; Bangla is three bytes each.
            ->withMaxInputLength(400_000);

        foreach (self::DROPPED as $element) {
            $config = $config->dropElement($element);
        }

        return self::$sanitizer = new HtmlSanitizer($config);
    }

    /** Keeps an <img> only when its src is one of our media URLs; rebuilds it from the allowed attributes. */
    private static function filterImages(string $html): string
    {
        return (string) preg_replace_callback('#<img\b([^>]*)/?>#i', static function (array $match): string {
            preg_match_all('#([a-z]+)="([^"]*)"#i', $match[1], $pairs, PREG_SET_ORDER);
            $attributes = [];
            foreach ($pairs as [, $name, $value]) {
                $attributes[strtolower($name)] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            $src = $attributes['src'] ?? '';
            if (! self::isOwnMedia($src)) {
                return '';
            }

            $out = '<img src="'.self::attr($src).'"';
            $out .= ' alt="'.self::attr($attributes['alt'] ?? '').'"';
            foreach (['width', 'height'] as $dimension) {
                $value = $attributes[$dimension] ?? '';
                if (preg_match('/^\d{1,5}$/', $value) === 1 && (int) $value > 0) {
                    $out .= ' '.$dimension.'="'.$value.'"';
                }
            }

            return $out.' />';
        }, $html);
    }

    /** The stable `/m/{id}/…` URL (relative, or on the app host), or a file under the media disk / CDN base. */
    public static function isOwnMedia(string $src): bool
    {
        if (str_starts_with($src, '/m/')) {
            return ! str_contains($src, '..');
        }

        $parts = parse_url($src);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || str_contains($src, '..')) {
            return false;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        $appHost = self::hostOf(self::appUrl());

        if ($appHost !== null && $host === $appHost && str_starts_with($path, '/m/')) {
            return true;
        }

        $base = rtrim(MediaUrls::url(''), '/');
        $baseHost = self::hostOf($base);
        $basePath = rtrim((string) (parse_url($base, PHP_URL_PATH) ?? ''), '/');

        return $baseHost !== null && $host === $baseHost && str_starts_with($path, $basePath.'/');
    }

    /** rel="noopener nofollow" on links that leave the site; mailto and our own hosts stay as they are. */
    private static function markExternalLinks(string $html): string
    {
        return (string) preg_replace_callback('#<a\b([^>]*)>#i', static function (array $match): string {
            preg_match('#href="([^"]*)"#i', $match[1], $href);
            if (! isset($href[1])) {
                return '<a>';
            }

            $url = html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (! self::isAllowedHref($url)) {
                return '<a>';
            }
            $host = self::hostOf($url);
            $internal = $host === null || in_array($host, self::internalHosts(), true);

            return '<a href="'.$href[1].'"'.($internal ? '' : ' rel="noopener nofollow"').'>';
        }, $html);
    }

    /**
     * http(s)/mailto (the sanitiser already refused every other scheme), or a site-relative link:
     * root-relative `/x` (not `//host`, not `/\host`) or a fragment `#x`. A bare relative path
     * would resolve differently under /bn, so it is dropped.
     */
    public static function isAllowedHref(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\\\\]/', $url) === 1) {
            return false;
        }
        if ($url[0] === '#') {
            return true;
        }
        if ($url[0] === '/') {
            return ! str_starts_with($url, '//');
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https', 'mailto'], true);
    }

    /** @return list<string> */
    private static function internalHosts(): array
    {
        $hosts = self::SITE_HOSTS;
        $app = self::hostOf(self::appUrl());
        if ($app !== null) {
            $hosts[] = $app;
        }

        return $hosts;
    }

    private static function appUrl(): string
    {
        $url = config('app.url');

        return is_string($url) ? $url : '';
    }

    private static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function isBlank(string $html): bool
    {
        if (preg_match('#<img\b#i', $html) === 1) {
            return false;
        }

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{00A0}") === '';
    }
}

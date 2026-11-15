<?php

declare(strict_types=1);

namespace App\Domain\Notification\DTOs;

/**
 * One approved utility WhatsApp template in one language, as the Inbox may send it.
 *
 * `body` is the approved copy with each positional parameter shown as `{{1}}`, `{{2}}`…
 * (Meta's notation) and the store's fixed values (hotline, store URL) already filled in, so
 * {@see self::render()} gives exactly the text the customer will read. `parameters` are the
 * library's variable names in positional order ({{1}} first) — labels, never values.
 */
final readonly class ApprovedWhatsAppTemplate
{
    /**
     * @param  'en'|'bn'  $locale
     * @param  list<string>  $parameters
     * @param  list<string>  $examples  a sample value per parameter, same order
     */
    public function __construct(
        public string $key,
        public string $providerTemplate,
        public string $language,
        public string $locale,
        public ?string $name,
        public string $body,
        public array $parameters,
        public array $examples,
    ) {}

    /** Meta's language code for a locale: `en`, or `bn_BD` (the code the order notifications send with). */
    public static function languageFor(string $locale): string
    {
        return $locale === 'en' ? 'en' : 'bn_BD';
    }

    /** @param  list<string>  $values  positional, {{1}} first; a missing value leaves its placeholder */
    public function render(array $values): string
    {
        return (string) preg_replace_callback('/\{\{(\d+)\}\}/', static function (array $m) use ($values): string {
            $value = $values[(int) $m[1] - 1] ?? null;

            return is_string($value) ? $value : $m[0];
        }, $this->body);
    }
}

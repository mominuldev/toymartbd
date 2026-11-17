<?php

declare(strict_types=1);

namespace App\Domain\Ai\ProductCopy;

use App\Domain\Ai\DTOs\AiPrompt;
use App\Domain\Ai\DTOs\AiResult;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Services\AiClient;
use App\Domain\Ai\Services\FigureGuard;
use App\Domain\Catalog\Models\Product;

/**
 * Product › Write with AI (Figma 538:82911). Drafts a title, description and SEO texts from the
 * product's own facts ({@see ProductCopyFacts}) and saves nothing: the editor fills its form and
 * the person presses Save product.
 *
 * Every answer is checked on the server: description HTML cut down to `<p> <ul> <li> <strong>`,
 * Bengali digits turned Western (rule 36), the length limits (title 70, SEO title 60, meta
 * description 160) and every figure against the facts ({@see FigureGuard}). An answer that breaks
 * a limit or names an unknown figure is sent back once with the problems listed; whatever is still
 * wrong after that is returned flagged (`too_long`, `unknown_figures`) — never silently cut.
 *
 * Calls a provider: never inside a database transaction (rule 4).
 */
final readonly class ProductCopyWriter
{
    public const array FIELDS = ['title', 'description', 'seo'];

    public const array LANGUAGES = ['en', 'bn'];

    public const array TONES = ['warm', 'playful', 'premium'];

    /** Character limits per output key. */
    public const array LIMITS = ['title' => 70, 'seo_title' => 60, 'meta_description' => 160];

    private const array TONE_TEXT = [
        'warm' => 'Warm and reassuring, like a trusted local shopkeeper talking to a parent.',
        'playful' => 'Playful and lively, with energy children and parents enjoy, but never silly or exaggerated.',
        'premium' => 'Polished and premium: calm, confident and focused on quality and care.',
    ];

    private const string SYSTEM = <<<'TXT'
        You write product copy for Toy Mart BD, an online toy and educational-products shop in Bangladesh. Your readers are Bangladeshi parents and gift buyers.

        Rules:
        - Use ONLY the facts you are given. If a fact is not listed, do not mention it.
        - Never invent safety claims, certifications (BSTI, CE, ASTM or any other), warranties, guarantees, dimensions, weights, piece counts, materials, awards, origin or ages that are not in the facts.
        - Never mention stock, delivery times, discounts or offers. Mention the price only if it is in the facts, written exactly as given.
        - Every number must be written with Western digits 0-9, also in Bangla text. Never use Bengali digits.
        - Bangla text is natural, simple Bangla in Bengali script that a parent in Dhaka would use; brand names may stay in Latin script.
        - title: the product's name as a shopper would search for it, at most 70 characters.
        - seo_title: at most 60 characters. meta_description: one or two sentences, at most 160 characters.
        - description_html: two or three short paragraphs followed by one bullet list of key points, in simple HTML using only <p>, <ul>, <li> and <strong>. No headings, links, images, emoji or other tags.
        - Answer with the JSON object only.
        TXT;

    public function __construct(private AiClient $client) {}

    /**
     * @param  list<string>  $fields  subset of {@see self::FIELDS}
     * @param  list<string>  $languages  subset of {@see self::LANGUAGES}
     */
    public function write(Product $product, array $fields, array $languages, string $tone, string $keywords, ?int $userId): ProductCopyDraft
    {
        $facts = ProductCopyFacts::of($product);
        $keys = self::outputKeys($fields);
        $schema = self::schema($languages, $keys);
        $user = self::userTurn($facts, $languages, $keys, $tone, $keywords);

        $result = $this->client->run(AiFeature::ProductCopy, new AiPrompt(self::SYSTEM, $user, $schema, 'product_copy'), $userId);
        [$drafts, $issues] = self::check($result, $languages, $keys, $facts);

        if ($issues !== []) {
            $retry = $user."\n\nYour previous answer was:\n".json_encode($drafts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ."\n\nWrite it again. Fix exactly these problems and keep the rest:\n- ".implode("\n- ", $issues);
            $result = $this->client->run(AiFeature::ProductCopy, new AiPrompt(self::SYSTEM, $retry, $schema, 'product_copy'), $userId);
            [$drafts] = self::check($result, $languages, $keys, $facts);
        }

        return new ProductCopyDraft(
            en: $drafts['en'] ?? null,
            bn: $drafts['bn'] ?? null,
            model: $result->model,
            facts: $facts->forDisplay(),
        );
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private static function outputKeys(array $fields): array
    {
        $keys = [];
        foreach (self::FIELDS as $f) {
            if (in_array($f, $fields, true)) {
                array_push($keys, ...match ($f) {
                    'title' => ['title'],
                    'description' => ['description_html'],
                    default => ['seo_title', 'meta_description'],
                });
            }
        }

        return $keys;
    }

    /**
     * Strict JSON Schema: exactly the languages and keys asked for.
     *
     * @param  list<string>  $languages
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function schema(array $languages, array $keys): array
    {
        $copy = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $keys,
            'properties' => array_fill_keys($keys, ['type' => 'string']),
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $languages,
            'properties' => array_fill_keys($languages, $copy),
        ];
    }

    /**
     * @param  list<string>  $languages
     * @param  list<string>  $keys
     */
    private static function userTurn(ProductCopyFacts $facts, array $languages, array $keys, string $tone, string $keywords): string
    {
        $lang = array_map(static fn (string $l): string => $l === 'en' ? '"en": English' : '"bn": Bangla (Bengali script, Western digits)', $languages);
        $lines = [
            'Facts about the product:',
            $facts->forPrompt(),
            '',
            'Write: '.implode(', ', $keys).'.',
            'Languages: '.implode('; ', $lang).'. Each language is written for its own readers, not translated word for word.',
            'Tone: '.(self::TONE_TEXT[$tone] ?? self::TONE_TEXT['warm']),
        ];
        if ($keywords !== '') {
            $lines[] = 'Search keywords to work in naturally where they fit the facts (never as a list): '.$keywords;
        }

        return implode("\n", $lines);
    }

    /**
     * Normalise, sanitise and check one answer.
     *
     * @param  list<string>  $languages
     * @param  list<string>  $keys
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>}
     */
    private static function check(AiResult $result, array $languages, array $keys, ProductCopyFacts $facts): array
    {
        $json = $result->json ?? [];
        $drafts = [];
        $issues = [];

        foreach ($languages as $lang) {
            $block = $json[$lang] ?? null;
            if (! is_array($block)) {
                throw new AiException(AiException::INVALID_OUTPUT, 'The answer is missing a language.');
            }
            $draft = ['title' => null, 'description_html' => null, 'seo_title' => null, 'meta_description' => null];
            $tooLong = [];
            $figures = [];

            foreach ($keys as $key) {
                $raw = $block[$key] ?? null;
                if (! is_string($raw)) {
                    throw new AiException(AiException::INVALID_OUTPUT, 'The answer is missing a field.');
                }
                $raw = self::westernDigits($raw);
                if ($key === 'description_html') {
                    $value = CopyHtml::clean($raw);
                    $text = CopyHtml::text($value);
                } else {
                    $value = $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                }
                if ($text === '') {
                    throw new AiException(AiException::INVALID_OUTPUT, 'The answer has an empty field.');
                }
                $draft[$key] = $value;

                $limit = self::LIMITS[$key] ?? null;
                if ($limit !== null && mb_strlen($text) > $limit) {
                    $tooLong[] = $key;
                    $issues[] = sprintf('%s.%s is %d characters; it must be at most %d. Write a shorter one; do not cut a word.', $lang, $key, mb_strlen($text), $limit);
                }
                foreach (FigureGuard::unknown($text, $facts->values()) as $figure) {
                    $figures[$figure] = true;
                    $issues[] = sprintf('%s.%s mentions "%s", which is not in the facts. Remove it.', $lang, $key, $figure);
                }
            }
            $drafts[$lang] = $draft + ['too_long' => $tooLong, 'unknown_figures' => array_map('strval', array_keys($figures))];
        }

        return [$drafts, $issues];
    }

    private static function westernDigits(string $text): string
    {
        return strtr($text, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    }
}

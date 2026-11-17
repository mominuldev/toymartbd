<?php

declare(strict_types=1);

namespace App\Domain\Ai\ProductCopy;

/**
 * One Write with AI answer. A language not asked for is null; inside a language, a field not
 * asked for is null. `too_long` lists keys still over their limit after the one retry;
 * `unknown_figures` lists figures in the text that match no fact.
 *
 * @phpstan-type Copy array{title: string|null, description_html: string|null, seo_title: string|null, meta_description: string|null, too_long: list<string>, unknown_figures: list<string>}
 */
final readonly class ProductCopyDraft
{
    /**
     * @param  array<string, mixed>|null  $en
     * @param  array<string, mixed>|null  $bn
     * @param  list<array{key: string, value: string}>  $facts
     */
    public function __construct(
        public ?array $en,
        public ?array $bn,
        public string $model,
        public array $facts,
    ) {}
}

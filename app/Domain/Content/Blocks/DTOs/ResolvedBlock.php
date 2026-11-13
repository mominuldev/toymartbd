<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\DTOs;

use App\Domain\Content\Blocks\Enums\BlockType;

/**
 * A block ready for the storefront: visible now, in this locale. `content` is single-language
 * (`x_bn` folded into `x`, English as the fallback). `data` carries the records the block shows
 * (models), keyed per type — the HTTP resource turns them into JSON.
 */
final readonly class ResolvedBlock
{
    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $id,
        public BlockType $type,
        public bool $showMobile,
        public bool $showDesktop,
        public array $content,
        public array $data = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Domain\Content\DTOs;

use App\Domain\Content\Enums\MenuLinkType;

/** One menu entry and its children, in display order. */
final readonly class MenuItemData
{
    /** @param  list<MenuItemData>  $children */
    public function __construct(
        public string $label,
        public ?string $labelBn,
        public MenuLinkType $linkType,
        public ?int $linkId = null,
        public ?string $url = null,
        public bool $isVisible = true,
        public bool $openInNewTab = false,
        public array $children = [],
    ) {}
}

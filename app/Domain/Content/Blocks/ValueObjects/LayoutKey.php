<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\ValueObjects;

use InvalidArgumentException;

/**
 * Which block list a draft block or a published version belongs to: the homepage (`home`) or
 * one static page (`page:{id}`). Stored as that string in `cms_blocks.placement`,
 * `cms_layout_versions.placement` and `cms_block_media.placement`, so each layout has its own
 * draft, its own version numbers and its own media usages on the same tables.
 */
final readonly class LayoutKey
{
    private const string HOME = 'home';

    private const string PAGE_PREFIX = 'page:';

    private function __construct(public string $value, public ?int $pageId) {}

    public static function home(): self
    {
        return new self(self::HOME, null);
    }

    public static function page(int $pageId): self
    {
        if ($pageId < 1) {
            throw new InvalidArgumentException('A page layout needs a page id.');
        }

        return new self(self::PAGE_PREFIX.$pageId, $pageId);
    }

    public static function fromString(string $value): self
    {
        if ($value === self::HOME) {
            return self::home();
        }
        if (preg_match('/^page:([1-9]\d{0,18})$/', $value, $m) === 1) {
            return self::page((int) $m[1]);
        }

        throw new InvalidArgumentException("Unknown CMS layout key [{$value}].");
    }

    public function isHome(): bool
    {
        return $this->pageId === null;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * PreviewToken type for a draft preview. The token's id is the page id for a page (so a token
     * opens exactly one page's draft) and the issuing user for the homepage.
     */
    public function previewTokenType(): string
    {
        return $this->isHome() ? 'home_draft' : 'page_draft';
    }
}

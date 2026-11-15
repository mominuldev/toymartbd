<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Catalog\Services\ProductDescription;
use App\Domain\Content\Blocks\Enums\BlockType;
use App\Domain\Content\Blocks\Services\HomeLayoutCache;
use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use App\Domain\Content\Models\CmsPage;

/**
 * A page's body text, for summaries (the default meta description, SEO subjects): its live
 * sections' Rich text, in order — or, for a page not yet built from sections, its legacy
 * `content` column. Bangla falls back to English section by section, as the storefront does.
 */
final class PageText
{
    public function __construct(private readonly HomeLayoutCache $layouts) {}

    public function html(CmsPage $page, bool $bangla = false): ?string
    {
        $live = $this->layouts->live(LayoutKey::page($page->id));
        if ($live === null) {
            return $bangla ? $page->content_bn : $page->content;
        }

        $parts = [];
        foreach ($live['blocks'] as $block) {
            if (($block['type'] ?? null) !== BlockType::RichText->value || ! ($block['is_enabled'] ?? true)) {
                continue;
            }
            $content = is_array($block['content'] ?? null) ? $block['content'] : [];
            $html = $bangla && is_string($content['html_bn'] ?? null) && $content['html_bn'] !== '' ? $content['html_bn'] : ($content['html'] ?? null);
            if (is_string($html) && $html !== '') {
                $parts[] = $html;
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    public function plain(CmsPage $page, bool $bangla = false): string
    {
        return ProductDescription::plainText($this->html($page, $bangla));
    }
}

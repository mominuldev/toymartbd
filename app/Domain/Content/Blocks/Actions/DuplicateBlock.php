<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Actions;

use App\Domain\Content\Blocks\DTOs\BlockSettings;
use App\Domain\Content\Blocks\Models\CmsBlock;

/** Copies a block (new identity, same content and settings) directly below the original. */
final class DuplicateBlock
{
    public function __construct(private readonly CreateBlock $create) {}

    public function handle(CmsBlock $block, ?int $userId): CmsBlock
    {
        $settings = new BlockSettings(
            content: $block->content,
            label: $block->label,
            isEnabled: $block->is_enabled,
            showEn: $block->show_en,
            showBn: $block->show_bn,
            showMobile: $block->show_mobile,
            showDesktop: $block->show_desktop,
            startsAt: $block->starts_at,
            endsAt: $block->ends_at,
            present: ['label', 'is_enabled', 'show_en', 'show_bn', 'show_mobile', 'show_desktop', 'starts_at', 'ends_at'],
        );

        return $this->create->handle($block->placement, $block->type, $settings, $block->position + 1, $userId);
    }
}

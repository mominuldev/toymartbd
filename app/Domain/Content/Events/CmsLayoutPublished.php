<?php

declare(strict_types=1);

namespace App\Domain\Content\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new version of a CMS placement went live (publish or revert). `placement` is the
 * BlockPlacement value (`home`). Listeners: RevalidateCmsLayout (storefront cache tag).
 */
final readonly class CmsLayoutPublished
{
    use Dispatchable;

    public function __construct(
        public string $placement,
        public int $version,
    ) {}
}

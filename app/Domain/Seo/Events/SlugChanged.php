<?php

declare(strict_types=1);

namespace App\Domain\Seo\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An entity's slug changed (docs/api/seo.md §1). Dispatched by the owning domain's
 * Action — pages, posts, guides — so the Seo module can write `slug_history` without
 * that domain depending on Seo. `$wasLive`: the old URL was public (a draft's never was).
 */
final readonly class SlugChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $type,
        public int $id,
        public string $oldSlug,
        public string $newSlug,
        public bool $wasLive = true,
        public ?int $actorId = null,
    ) {}
}

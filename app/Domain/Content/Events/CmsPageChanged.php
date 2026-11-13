<?php

declare(strict_types=1);

namespace App\Domain\Content\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A static page was created, edited, published, scheduled, archived, or its SEO changed. Held
 * until the transaction commits. `previousSlug` is set only when the slug changed.
 */
final readonly class CmsPageChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $pageId,
        public string $slug,
        public ?string $previousSlug = null,
    ) {}
}

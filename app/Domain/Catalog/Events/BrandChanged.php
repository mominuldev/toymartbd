<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A brand was created, edited or archived. Dispatched from inside the Action's
 * transaction but held until it commits (ShouldDispatchAfterCommit), so a rolled
 * back write never reaches a listener. `previousSlug` is set only when the slug
 * changed, so listeners can purge the old URL too.
 */
final readonly class BrandChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $brandId,
        public string $slug,
        public ?string $previousSlug = null,
    ) {}
}

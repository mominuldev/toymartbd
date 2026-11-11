<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A product was created, edited (fields, categories, variants or relations) or
 * archived. Held until the Action's transaction commits, so a rolled-back write never
 * reaches a listener. `previousSlug` is set only when the slug changed, so listeners
 * can purge the old URL too.
 */
final readonly class ProductChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $productId,
        public string $slug,
        public ?string $previousSlug = null,
    ) {}
}

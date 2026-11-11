<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A category was created, edited, moved or archived. Held until the Action's
 * transaction commits. `previousSlug` is set only when the slug changed.
 * `descendantSlugs` lists the subtree below it whenever its position in the tree
 * (or its name) changed, because their breadcrumbs change with it.
 */
final readonly class CategoryChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $descendantSlugs
     */
    public function __construct(
        public int $categoryId,
        public string $slug,
        public ?string $previousSlug = null,
        public array $descendantSlugs = [],
    ) {}
}

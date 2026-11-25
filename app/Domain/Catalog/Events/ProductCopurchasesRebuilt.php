<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The co-purchase projection was rebuilt (RebuildProductCopurchases). Every product page
 * carries the `products` tag and its "Frequently bought together" block reads the
 * projection, so Content purges that tag once per rebuild.
 */
final readonly class ProductCopurchasesRebuilt implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $pairs) {}
}

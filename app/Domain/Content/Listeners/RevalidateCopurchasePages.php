<?php

declare(strict_types=1);

namespace App\Domain\Content\Listeners;

use App\Domain\Catalog\Events\ProductCopurchasesRebuilt;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;

/**
 * ProductCopurchasesRebuilt → purge `products`: every product page carries it, and its
 * "Frequently bought together" block reads the rebuilt projection (docs/api/revalidation.md).
 */
final class RevalidateCopurchasePages
{
    public function handle(ProductCopurchasesRebuilt $event): void
    {
        RevalidateStorefrontTags::dispatch(['products']);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

/**
 * Who is writing a product, and which field-level abilities they hold. The caller
 * (controller) resolves the abilities from the ProductPolicy; the domain enforces
 * them where it knows what actually changes:
 *
 * - `canChangePrices` false: an update that changes a selling price (price,
 *   compare-at, wholesale) or creates a priced variant is refused with 403. Saving
 *   the same prices back is fine.
 * - `canSetCost` false: any cost_price in the document is refused with 403.
 *
 * The default (everything allowed, no actor) is for trusted internal callers such as
 * seeders and imports.
 */
final readonly class ProductWriteContext
{
    public function __construct(
        public ?int $actorId = null,
        public bool $canChangePrices = true,
        public bool $canSetCost = true,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Catalog\Models\Product;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Products (catalog). Archived, never deleted — `delete` is the archive ability and
 * needs catalog.archive.
 *
 * Two field-level abilities sit on top of create/update:
 * - `updatePrice` (catalog.update_price): change a selling price (price,
 *   compare-at, wholesale) or add a priced variant on update. A content editor can
 *   save copy without it as long as the prices come back unchanged.
 * - `viewCost` (catalog.view_cost): see and set `cost_price`. Without it the field
 *   is absent from responses and must be absent from writes.
 */
final class ProductPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CatalogView);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->allows($user, Permission::CatalogView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CatalogCreate);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->allows($user, Permission::CatalogUpdate);
    }

    public function updatePrice(User $user, Product $product): bool
    {
        return $this->allows($user, Permission::CatalogUpdatePrice);
    }

    public function viewCost(User $user): bool
    {
        return $this->allows($user, Permission::CatalogViewCost);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->allows($user, Permission::CatalogArchive);
    }
}

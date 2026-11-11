<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Catalog\Models\ProductAttribute;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Product attributes (catalog). `delete` removes an attribute no variant uses and
 * needs catalog.archive, the catalog's "take it away" permission.
 */
final class ProductAttributePolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CatalogView);
    }

    public function view(User $user, ProductAttribute $attribute): bool
    {
        return $this->allows($user, Permission::CatalogView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CatalogCreate);
    }

    public function update(User $user, ProductAttribute $attribute): bool
    {
        return $this->allows($user, Permission::CatalogUpdate);
    }

    public function delete(User $user, ProductAttribute $attribute): bool
    {
        return $this->allows($user, Permission::CatalogArchive);
    }
}

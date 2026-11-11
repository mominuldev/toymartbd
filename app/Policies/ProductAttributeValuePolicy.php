<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Attribute values, managed under their attribute. Adding a value is a
 * catalog.create; editing or reordering a catalog.update; deleting an unused one a
 * catalog.archive.
 */
final class ProductAttributeValuePolicy extends Policy
{
    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CatalogCreate);
    }

    public function update(User $user, ProductAttributeValue $value): bool
    {
        return $this->allows($user, Permission::CatalogUpdate);
    }

    public function delete(User $user, ProductAttributeValue $value): bool
    {
        return $this->allows($user, Permission::CatalogArchive);
    }
}

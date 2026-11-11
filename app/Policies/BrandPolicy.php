<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Catalog\Models\Brand;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Brands (catalog). Archived, never deleted — `delete` is the archive ability and
 * needs catalog.archive.
 */
final class BrandPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CatalogView);
    }

    public function view(User $user, Brand $brand): bool
    {
        return $this->allows($user, Permission::CatalogView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CatalogCreate);
    }

    public function update(User $user, Brand $brand): bool
    {
        return $this->allows($user, Permission::CatalogUpdate);
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->allows($user, Permission::CatalogArchive);
    }
}

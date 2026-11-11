<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Catalog\Models\Category;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Categories (catalog). Archived, never deleted — `delete` is the archive ability
 * and needs catalog.archive. Moving within the tree is an `update`.
 */
final class CategoryPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CatalogView);
    }

    public function view(User $user, Category $category): bool
    {
        return $this->allows($user, Permission::CatalogView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CatalogCreate);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->allows($user, Permission::CatalogUpdate);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->allows($user, Permission::CatalogArchive);
    }
}

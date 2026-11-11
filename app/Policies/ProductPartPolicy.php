<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Variants, images and relations are parts of the product aggregate: they are only
 * read and written through the product's endpoints and Actions, so they follow the
 * product's catalog permissions. Registered for ProductVariant, ProductImage and
 * ProductRelation so that every model has a Policy (rule 21).
 */
final class ProductPartPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CatalogView);
    }

    public function view(User $user, Model $part): bool
    {
        return $this->allows($user, Permission::CatalogView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CatalogUpdate);
    }

    public function update(User $user, Model $part): bool
    {
        return $this->allows($user, Permission::CatalogUpdate);
    }

    public function delete(User $user, Model $part): bool
    {
        return $this->allows($user, Permission::CatalogArchive);
    }
}

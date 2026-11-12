<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * `seo_meta` and `slug_history` (Seo domain). A product's rows are read and written
 * through the product document, so those endpoints check the product's catalog
 * permissions; this policy covers the models themselves (rule 21) for the SEO module
 * screens of Phase 7: seo.view to read, seo.manage to change. History is insert-only.
 */
final class SeoPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::SeoView);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allows($user, Permission::SeoView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::SeoManage);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allows($user, Permission::SeoManage);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}

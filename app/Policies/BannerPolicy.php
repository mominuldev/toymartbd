<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Content\Models\Banner;
use App\Models\Enums\Permission;
use App\Models\User;

/** Banners (Online store): reading needs content.view, every write content.manage. */
final class BannerPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ContentView);
    }

    public function view(User $user, Banner $model): bool
    {
        return $this->allows($user, Permission::ContentView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ContentManage);
    }

    public function update(User $user, Banner $model): bool
    {
        return $this->allows($user, Permission::ContentManage);
    }

    public function delete(User $user, Banner $model): bool
    {
        return $this->allows($user, Permission::ContentManage);
    }
}

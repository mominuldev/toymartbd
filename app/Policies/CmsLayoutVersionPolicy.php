<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Published homepage versions. Listing needs content.view; publishing and reverting (which
 * create a version) need content.manage. Versions are immutable: no update, no delete.
 */
final class CmsLayoutVersionPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ContentView);
    }

    public function view(User $user, CmsLayoutVersion $version): bool
    {
        return $this->allows($user, Permission::ContentView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ContentManage);
    }

    public function update(User $user, CmsLayoutVersion $version): bool
    {
        return false;
    }

    public function delete(User $user, CmsLayoutVersion $version): bool
    {
        return false;
    }
}

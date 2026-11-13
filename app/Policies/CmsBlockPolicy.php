<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Models\Enums\Permission;
use App\Models\User;

/** Homepage builder blocks (draft). Reading and previewing need content.view; any change needs content.manage. */
final class CmsBlockPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ContentView);
    }

    public function view(User $user, CmsBlock $block): bool
    {
        return $this->allows($user, Permission::ContentView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ContentManage);
    }

    public function update(User $user, CmsBlock $block): bool
    {
        return $this->allows($user, Permission::ContentManage);
    }

    public function delete(User $user, CmsBlock $block): bool
    {
        return $this->allows($user, Permission::ContentManage);
    }
}

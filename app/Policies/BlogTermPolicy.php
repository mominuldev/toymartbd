<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/** Blog categories, tags and authors: content.view to read, content.manage to write. */
final class BlogTermPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ContentView);
    }

    public function view(User $user, mixed $term = null): bool
    {
        return $this->allows($user, Permission::ContentView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ContentManage);
    }

    public function update(User $user, mixed $term = null): bool
    {
        return $this->allows($user, Permission::ContentManage);
    }

    public function delete(User $user, mixed $term = null): bool
    {
        return $this->allows($user, Permission::ContentManage);
    }
}

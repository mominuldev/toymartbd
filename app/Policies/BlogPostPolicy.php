<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Blog posts, their guide sections and product links (content). Viewing needs content.view;
 * every write — create, edit, schedule, publish, preview link, delete a draft — content.manage.
 */
final class BlogPostPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ContentView);
    }

    public function view(User $user, mixed $post = null): bool
    {
        return $this->allows($user, Permission::ContentView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ContentManage);
    }

    public function update(User $user, mixed $post = null): bool
    {
        return $this->allows($user, Permission::ContentManage);
    }

    public function delete(User $user, mixed $post = null): bool
    {
        return $this->allows($user, Permission::ContentManage);
    }
}

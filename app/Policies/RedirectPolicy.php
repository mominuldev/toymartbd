<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Seo\Models\Redirect;
use App\Models\Enums\Permission;
use App\Models\User;

/** Manual redirects (SEO → Redirects): seo.view to read, seo.manage to change or delete. */
final class RedirectPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::SeoView);
    }

    public function view(User $user, Redirect $redirect): bool
    {
        return $this->allows($user, Permission::SeoView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::SeoManage);
    }

    public function update(User $user, Redirect $redirect): bool
    {
        return $this->allows($user, Permission::SeoManage);
    }

    public function delete(User $user, Redirect $redirect): bool
    {
        return $this->allows($user, Permission::SeoManage);
    }
}

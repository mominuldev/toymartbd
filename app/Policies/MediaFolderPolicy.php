<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Catalog\Media\Models\MediaFolder;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Media-library folders: listed with the library (catalog.view or content.view);
 * created, renamed and deleted with media.manage. A system folder's lock is a state
 * rule (409), not a permission.
 */
final class MediaFolderPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CatalogView) || $this->allows($user, Permission::ContentView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::MediaManage);
    }

    public function update(User $user, MediaFolder $folder): bool
    {
        return $this->allows($user, Permission::MediaManage);
    }

    public function delete(User $user, MediaFolder $folder): bool
    {
        return $this->allows($user, Permission::MediaManage);
    }
}

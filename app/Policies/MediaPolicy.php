<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Catalog\Media\Models\Media;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * The media library (docs/api/media-library.md, decision 7). Viewing needs catalog.view
 * or content.view (product editors and page editors both pick files); uploading,
 * editing, moving and replacing need media.manage; deleting and bulk-downloading
 * originals need media.delete (owner and admin roles only).
 */
final class MediaPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CatalogView) || $this->allows($user, Permission::ContentView);
    }

    public function view(User $user, Media $media): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::MediaManage);
    }

    public function update(User $user, Media $media): bool
    {
        return $this->allows($user, Permission::MediaManage);
    }

    /** Bulk move: no single record. */
    public function updateAny(User $user): bool
    {
        return $this->allows($user, Permission::MediaManage);
    }

    public function delete(User $user, Media $media): bool
    {
        return $this->allows($user, Permission::MediaDelete);
    }

    /** Bulk delete and the originals zip. */
    public function deleteAny(User $user): bool
    {
        return $this->allows($user, Permission::MediaDelete);
    }
}

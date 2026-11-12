<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Catalog\Reviews\Models\Review;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Product reviews (P4-CAT-12). Staff read the moderation queue with catalog.view;
 * approving, rejecting and replying need reviews.moderate. Reviews are written by
 * customers through the storefront (`auth:customer`), never by staff, and are never
 * deleted — a rejected review stays on record.
 */
final class ReviewPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CatalogView);
    }

    public function view(User $user, Review $review): bool
    {
        return $this->allows($user, Permission::CatalogView);
    }

    public function moderate(User $user, Review $review): bool
    {
        return $this->allows($user, Permission::ReviewsModerate);
    }
}

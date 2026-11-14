<?php

declare(strict_types=1);

namespace App\Domain\Staff\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A staff write refused because it would lock the business out of its own admin:
 * changing or deactivating yourself, or leaving no active owner (`super_admin`).
 * Rendered as a 409 `conflict` problem; the message is passed through as `detail`.
 */
final class StaffConflict extends ConflictHttpException
{
    public static function self(): self
    {
        return new self((string) __('staff.conflict_self'));
    }

    public static function lastOwner(): self
    {
        return new self((string) __('staff.conflict_last_owner'));
    }

    public static function twoFactorAlreadyOn(): self
    {
        return new self((string) __('staff.conflict_two_factor_on'));
    }

    public static function twoFactorNotStarted(): self
    {
        return new self((string) __('staff.conflict_two_factor_not_started'));
    }

    public static function twoFactorRequired(): self
    {
        return new self((string) __('staff.conflict_two_factor_required'));
    }

    public static function ownerRole(): self
    {
        return new self((string) __('staff.conflict_owner_role'));
    }
}

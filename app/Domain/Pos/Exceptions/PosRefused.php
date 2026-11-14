<?php

declare(strict_types=1);

namespace App\Domain\Pos\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A register operation the current state does not allow — rendered as a 409 `conflict`. */
final class PosRefused extends ConflictHttpException
{
    public static function sessionClosed(): self
    {
        return new self((string) __('pos.session_closed'));
    }

    public static function registerBusy(): self
    {
        return new self((string) __('pos.register_busy'));
    }

    public static function cashierBusy(): self
    {
        return new self((string) __('pos.cashier_busy'));
    }

    public static function locationNotSelling(): self
    {
        return new self((string) __('pos.location_not_selling'));
    }

    public static function holdResumed(): self
    {
        return new self((string) __('pos.hold_resumed'));
    }
}

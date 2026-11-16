<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** An owner-capital write the books cannot accept — rendered as a 409 `conflict`, in the caller's language. */
final class CapitalEntryRefused extends ConflictHttpException
{
    public static function alreadyReversed(): self
    {
        return new self((string) __('capital.already_reversed'));
    }

    public static function isReversal(): self
    {
        return new self((string) __('capital.reversal_not_reversible'));
    }

    public static function inactiveHolder(): self
    {
        return new self((string) __('capital.holder_inactive'));
    }
}

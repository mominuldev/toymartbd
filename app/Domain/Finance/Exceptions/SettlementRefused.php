<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A settlement step the books cannot accept — rendered as a 409 `conflict`. */
final class SettlementRefused extends ConflictHttpException
{
    public static function duplicate(string $reference): self
    {
        return new self("A settlement with reference {$reference} was already imported for this courier.");
    }

    public static function alreadyReconciled(): self
    {
        return new self('The settlement is already reconciled.');
    }

    public static function unresolved(int $count): self
    {
        return new self("{$count} variance line(s) still need a decision (accept or exclude) before the settlement can be confirmed.");
    }

    public static function notAcceptable(string $variance): self
    {
        return new self("A '{$variance}' line cannot be accepted — exclude it and take it up with the courier.");
    }

    public static function unknownProvider(string $code): self
    {
        return new self("Unknown courier provider '{$code}'.");
    }
}

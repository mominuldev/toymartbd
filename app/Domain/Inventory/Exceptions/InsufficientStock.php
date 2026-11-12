<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A stock write refused because not enough stock is available. Carries every short
 * line, so checkout can show a per-line message and never partially accept an order
 * (DATABASE-DESIGN §4.1). Rendered as a 409 `conflict` problem.
 */
final class InsufficientStock extends ConflictHttpException
{
    /** @param  array<int, array{requested: int, available: int}>  $lines  variant id → shortfall */
    private function __construct(string $message, public readonly array $lines)
    {
        parent::__construct($message);
    }

    /** @param  array<int, array{requested: int, available: int}>  $lines */
    public static function forLines(array $lines): self
    {
        ksort($lines);

        return new self('Not enough stock for '.count($lines).' item(s).', $lines);
    }
}

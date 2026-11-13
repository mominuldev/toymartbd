<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A promotion refused at the moment of use (PromotionUsages::record — the re-check under
 * the promotion's row lock): its total or per-customer limit, its budget or a flash
 * allotment ran out since the cart was priced. The order is not placed; the customer
 * re-prices and sees the new total. `flashSoldOut` = it was a flash allotment.
 */
final class PromotionRejected extends ConflictHttpException
{
    public function __construct(public readonly int $promotionId, public readonly bool $flashSoldOut, string $message)
    {
        parent::__construct($message);
    }
}

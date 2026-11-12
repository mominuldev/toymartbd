<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A return or refund step the current state does not allow. Rendered as a 409 `conflict`. */
final class ReturnNotAllowed extends ConflictHttpException
{
    public static function orderStatus(string $number, string $status): self
    {
        return new self("Order {$number} is {$status}; only a delivered or partially returned order takes a return.");
    }

    public static function returnStatus(string $number, string $from, string $to): self
    {
        return new self("Return {$number} cannot move from {$from} to {$to}.");
    }

    public static function refundStatus(int $id, string $from, string $to): self
    {
        return new self("Refund {$id} cannot move from {$from} to {$to}.");
    }

    public static function aboveThreshold(int $id, string $threshold): self
    {
        return new self("Refund {$id} is above ৳{$threshold} and needs a manager's approval (refunds.approve).");
    }

    public static function exceedsCaptured(int $id, string $refundable): self
    {
        return new self("Refund {$id} is more than the ৳{$refundable} still refundable on this order's captured payments.");
    }

    public static function nothingCaptured(int $id): self
    {
        return new self("Refund {$id} has no captured payment to refund against.");
    }
}

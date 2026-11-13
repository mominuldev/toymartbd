<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A purchase-order move its state machine refuses (PurchaseStatus). 409 `conflict`. */
final class PurchaseConflict extends ConflictHttpException
{
    public static function notEditable(string $number, string $status): self
    {
        return new self(__('purchasing.conflict.not_editable', ['number' => $number, 'status' => $status]));
    }

    public static function transition(string $number, string $from, string $to): self
    {
        return new self(__('purchasing.conflict.transition', ['number' => $number, 'from' => $from, 'to' => $to]));
    }

    public static function notReceivable(string $number, string $status): self
    {
        return new self(__('purchasing.conflict.not_receivable', ['number' => $number, 'status' => $status]));
    }

    public static function alreadyReceived(string $number): self
    {
        return new self(__('purchasing.conflict.already_received', ['number' => $number]));
    }
}

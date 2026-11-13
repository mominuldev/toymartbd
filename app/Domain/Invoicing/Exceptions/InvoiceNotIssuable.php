<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** An invoice cannot be issued for this order / PO in its current state. 409 `conflict`. */
final class InvoiceNotIssuable extends ConflictHttpException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function cancelledOrder(string $number): self
    {
        return new self((string) __('invoices.conflict.cancelled_order', ['number' => $number]), 'cancelled');
    }

    public static function purchaseNotOrdered(string $reference, string $status): self
    {
        return new self((string) __('invoices.conflict.purchase_not_ordered', ['number' => $reference, 'status' => $status]), 'not_ordered');
    }
}

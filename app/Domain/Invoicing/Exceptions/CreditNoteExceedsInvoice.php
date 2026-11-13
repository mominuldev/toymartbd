<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Issuing this credit note would credit more than the invoice total. Refused, nothing
 * written: the total credited against an invoice never exceeds what it charged.
 */
final class CreditNoteExceedsInvoice extends ConflictHttpException
{
    public function __construct(
        public readonly string $invoiceNumber,
        public readonly string $requested,
        public readonly string $remaining,
    ) {
        parent::__construct("Crediting {$requested} against invoice {$invoiceNumber} would exceed its uncredited {$remaining}.");
    }
}

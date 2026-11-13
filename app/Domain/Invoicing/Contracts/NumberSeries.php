<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Contracts;

/**
 * A gap-free document number series kept in `invoice_number_sequences` (one counter row per
 * series per Dhaka year): `INV`, `PINV` ({@see \App\Domain\Invoicing\Enums\InvoiceType}) and
 * `CN` ({@see \App\Domain\Invoicing\Enums\CreditNoteSeries}).
 */
interface NumberSeries
{
    /** The prefix and the `series` column value, at most 8 characters. */
    public function series(): string;
}

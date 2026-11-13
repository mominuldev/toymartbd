<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use App\Domain\Invoicing\Contracts\NumberSeries;

/** The credit-note number series: `CN-YY-NNNNNN`, gap-free per Dhaka year (P8 batch 2). */
enum CreditNoteSeries: string implements NumberSeries
{
    case CreditNote = 'CN';

    public function series(): string
    {
        return $this->value;
    }
}

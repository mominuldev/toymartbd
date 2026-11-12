<?php

declare(strict_types=1);

namespace App\Domain\Payment\Enums;

enum RefundType: string
{
    case Full = 'full';
    case Partial = 'partial';
}

<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/** `orders.risk_level` — bucketed from our advisory score (§5.2). Never auto-cancels. */
enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}

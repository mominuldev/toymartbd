<?php

declare(strict_types=1);

namespace App\Domain\Customer\Enums;

/** `customers.status`. Risk lists (watch/blacklist) live on customer_risk_profiles, not here. */
enum CustomerStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
}

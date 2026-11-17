<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Enums;

/** What sort of thing a suggested action asks the owner to do. */
enum ActionKind: string
{
    case Restock = 'restock';
    case Campaign = 'campaign';
    case VerifyOrders = 'verify_orders';
    case BanglaCopy = 'bangla_copy';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

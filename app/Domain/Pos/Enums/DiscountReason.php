<?php

declare(strict_types=1);

namespace App\Domain\Pos\Enums;

/**
 * Why a cashier gave a discount — a fixed list (owner request 2026-11-15), so the reasons can
 * be counted. Stored as the code on `pos_sales.discount_reason`; each screen shows its own
 * language's label.
 */
enum DiscountReason: string
{
    case RegularCustomer = 'regular_customer';
    case BulkPurchase = 'bulk_purchase';
    case DamagedPackaging = 'damaged_packaging';
    case PriceMatch = 'price_match';
    case StaffPurchase = 'staff_purchase';
    case Promotional = 'promotional';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Enums;

/**
 * Where a suggested action's button goes. The AI names a target type and, for the types that
 * need one, an id it was given in the facts; {@see self::path()} builds the admin path on the
 * server. A model-made URL is never stored or shown.
 */
enum ActionTarget: string
{
    case Product = 'product';
    case RestockForecast = 'restock_forecast';
    case PurchaseOrderNew = 'purchase_order_new';
    case LowStock = 'low_stock';
    case VerificationQueue = 'verification_queue';
    case FailedBookings = 'failed_bookings';
    case Promotion = 'promotion';
    case Promotions = 'promotions';
    case Coupons = 'coupons';
    case SalesReport = 'sales_report';
    case None = 'none';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function needsId(): bool
    {
        return $this === self::Product || $this === self::Promotion;
    }

    /** The admin SPA path, or null when there is nothing to open. */
    public function path(?int $id): ?string
    {
        return match ($this) {
            self::Product => $id === null ? null : "/products/{$id}",
            self::Promotion => $id === null ? '/marketing/promotions' : "/marketing/promotions/{$id}",
            self::RestockForecast => '/inventory/restock',
            self::PurchaseOrderNew => '/purchasing/new',
            self::LowStock => '/inventory/alerts',
            self::VerificationQueue => '/risk/queue',
            self::FailedBookings => '/shipments/failed',
            self::Promotions => '/marketing/promotions',
            self::Coupons => '/marketing/coupons',
            self::SalesReport => '/analytics/sales',
            self::None => null,
        };
    }
}

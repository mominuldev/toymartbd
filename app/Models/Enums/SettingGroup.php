<?php

declare(strict_types=1);

namespace App\Models\Enums;

/** Groups for `settings` (PRD P-04: store, courier, payment, notification, tracking). */
enum SettingGroup: string
{
    case Store = 'store';
    case Checkout = 'checkout';
    case Delivery = 'delivery';
    case Courier = 'courier';
    case Payment = 'payment';
    case Notification = 'notification';
    case Tracking = 'tracking';
    case Seo = 'seo';
    case Localization = 'localization';
    case System = 'system';
    case Risk = 'risk';
}

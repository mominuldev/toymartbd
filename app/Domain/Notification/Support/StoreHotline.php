<?php

declare(strict_types=1);

namespace App\Domain\Notification\Support;

use App\Models\Setting;

/**
 * The hotline printed in SMS, WhatsApp and email. Admin → Settings → Store → Business is the
 * single source (it saves the invoice company hotline, `invoice.company.hotline`); the
 * STORE_HOTLINE env value (`notifications.hotline`) applies until the owner saves one.
 *
 * Reads the setting by key rather than through App\Domain\Invoicing\Services\CompanyDetails,
 * which Notification may not import (deptrac).
 */
final class StoreHotline
{
    public const string SETTING_KEY = 'invoice.company.hotline';

    public static function current(): string
    {
        $saved = Setting::get(self::SETTING_KEY);
        if (is_string($saved) && trim($saved) !== '') {
            return trim($saved);
        }

        return (string) config('notifications.hotline');
    }
}

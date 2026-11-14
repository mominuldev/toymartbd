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

    /** Sample numbers from the designs and .env.example — never shown or dialled as real. */
    public const array PLACEHOLDERS = ['09600123456', '09610123456', '01712345678'];

    public static function current(): string
    {
        $saved = Setting::get(self::SETTING_KEY);
        if (is_string($saved) && trim($saved) !== '') {
            return trim($saved);
        }

        return (string) config('notifications.hotline');
    }

    /** The current hotline unless it is empty or a design sample; null means "not set yet". */
    public static function confirmed(): ?string
    {
        $hotline = self::current();
        $digits = preg_replace('/\D+/', '', $hotline) ?? '';

        return $digits === '' || in_array($digits, self::PLACEHOLDERS, true) ? null : $hotline;
    }
}

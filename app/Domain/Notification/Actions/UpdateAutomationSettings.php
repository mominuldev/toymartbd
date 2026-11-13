<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Services\AutomationSettings;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Saves Settings → Automations' values (P8): review delay and quiet hours, and (P8 batch 3) the
 * birthday reminder's lead days and its offer. The birthday values are optional — left out, they
 * stay as they are. Saving the offer once replaces the placeholders, on or off.
 */
final readonly class UpdateAutomationSettings
{
    /**
     * @param  array{enabled: bool, type: 'percent'|'fixed', value: string, max_discount: string|null, min_order_amount: string, valid_days: int}|null  $offer
     */
    public function handle(int $reviewDelayDays, string $quietFrom, string $quietTo, ?int $userId, ?int $birthdayLeadDays = null, ?array $offer = null): void
    {
        DB::transaction(static function () use ($reviewDelayDays, $quietFrom, $quietTo, $userId, $birthdayLeadDays, $offer): void {
            $group = SettingGroup::Notification;
            Setting::put(AutomationSettings::REVIEW_DELAY_DAYS, $reviewDelayDays, $group, false, $userId);
            Setting::put(AutomationSettings::QUIET_FROM, $quietFrom, $group, false, $userId);
            Setting::put(AutomationSettings::QUIET_TO, $quietTo, $group, false, $userId);

            if ($birthdayLeadDays !== null) {
                Setting::put(AutomationSettings::BIRTHDAY_LEAD_DAYS, $birthdayLeadDays, $group, false, $userId);
            }
            if ($offer !== null) {
                Setting::put(AutomationSettings::OFFER_ENABLED, $offer['enabled'], $group, false, $userId);
                Setting::put(AutomationSettings::OFFER_TYPE, $offer['type'], $group, false, $userId);
                Setting::put(AutomationSettings::OFFER_VALUE, $offer['value'], $group, false, $userId);
                Setting::put(AutomationSettings::OFFER_MAX_DISCOUNT, $offer['type'] === 'percent' ? $offer['max_discount'] : null, $group, false, $userId);
                Setting::put(AutomationSettings::OFFER_MIN_ORDER, $offer['min_order_amount'], $group, false, $userId);
                Setting::put(AutomationSettings::OFFER_VALID_DAYS, $offer['valid_days'], $group, false, $userId);
            }
        });
    }
}

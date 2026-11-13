<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Services\AutomationSettings;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/** Saves Settings → Automations' scheduling values (P8): review delay and quiet hours. */
final readonly class UpdateAutomationSettings
{
    public function handle(int $reviewDelayDays, string $quietFrom, string $quietTo, ?int $userId): void
    {
        DB::transaction(static function () use ($reviewDelayDays, $quietFrom, $quietTo, $userId): void {
            Setting::put(AutomationSettings::REVIEW_DELAY_DAYS, $reviewDelayDays, SettingGroup::Notification, false, $userId);
            Setting::put(AutomationSettings::QUIET_FROM, $quietFrom, SettingGroup::Notification, false, $userId);
            Setting::put(AutomationSettings::QUIET_TO, $quietTo, SettingGroup::Notification, false, $userId);
        });
    }
}

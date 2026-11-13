<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Models\Enums\SettingGroup;
use App\Models\Setting;

/**
 * The tunable parts of the scheduled automations (P8), edited on Settings → Automations and
 * stored in `settings` (group `notification`, keys `automation.*`). Whether an automation
 * runs at all is its templates' on/off switch, as for every other event on that screen.
 *
 *  - review request: `delay_days` after delivery (default 3, 1–30) and the Dhaka quiet
 *    hours during which nothing is sent (default 21:00–09:00; the hourly run catches up after);
 *  - birthday: sent once a day at {@see self::BIRTHDAY_SEND_AT} Dhaka (fixed, scheduled in
 *    routes/console.php).
 *
 * Not cached (see MessagingSettings: a long-running worker must see a change at once).
 */
final class AutomationSettings
{
    public const string REVIEW_DELAY_DAYS = 'automation.review_request.delay_days';

    public const string QUIET_FROM = 'automation.quiet_hours.from';

    public const string QUIET_TO = 'automation.quiet_hours.to';

    public const int DEFAULT_DELAY_DAYS = 3;

    public const string DEFAULT_QUIET_FROM = '21:00';

    public const string DEFAULT_QUIET_TO = '09:00';

    /** Delivered orders older than delay + this are never asked (e.g. on first deploy). */
    public const int REVIEW_LOOKBACK_DAYS = 7;

    public const string BIRTHDAY_SEND_AT = '10:00';

    public function reviewDelayDays(): int
    {
        $value = $this->stored()[self::REVIEW_DELAY_DAYS] ?? null;

        return is_int($value) && $value >= 1 && $value <= 30 ? $value : self::DEFAULT_DELAY_DAYS;
    }

    /** @return array{from: string, to: string} */
    public function quietHours(): array
    {
        $stored = $this->stored();
        $from = $stored[self::QUIET_FROM] ?? null;
        $to = $stored[self::QUIET_TO] ?? null;

        return [
            'from' => is_string($from) && preg_match('/^\d{2}:\d{2}$/', $from) === 1 ? $from : self::DEFAULT_QUIET_FROM,
            'to' => is_string($to) && preg_match('/^\d{2}:\d{2}$/', $to) === 1 ? $to : self::DEFAULT_QUIET_TO,
        ];
    }

    /** @return array{review_delay_days: int, quiet_from: string, quiet_to: string, birthday_send_at: string} */
    public function adminView(): array
    {
        $quiet = $this->quietHours();

        return [
            'review_delay_days' => $this->reviewDelayDays(),
            'quiet_from' => $quiet['from'],
            'quiet_to' => $quiet['to'],
            'birthday_send_at' => self::BIRTHDAY_SEND_AT,
        ];
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return Setting::query()
            ->where('group', SettingGroup::Notification->value)
            ->whereIn('key', [self::REVIEW_DELAY_DAYS, self::QUIET_FROM, self::QUIET_TO])
            ->get()
            ->mapWithKeys(static fn (Setting $s): array => [$s->key => $s->value])
            ->all();
    }
}

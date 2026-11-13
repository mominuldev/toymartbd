<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Marketing\Contracts\PersonalCouponTerms;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Support\Money;

/**
 * The tunable parts of the scheduled automations (P8), edited on Settings → Automations and
 * stored in `settings` (group `notification`, keys `automation.*`). Whether an automation
 * runs at all is its templates' on/off switch, as for every other event on that screen.
 *
 *  - review request: `delay_days` after delivery (default 3, 1–30) and the Dhaka quiet
 *    hours during which nothing is sent (default 21:00–09:00; the hourly run catches up after);
 *  - birthday reminder (P8 batch 3): to the PARENT, `lead_days` before each child's birthday
 *    (default 7 — PLACEHOLDER, owner to confirm; 0 = on the day), from
 *    {@see self::BIRTHDAY_SEND_AT} Dhaka, outside the quiet hours;
 *  - birthday offer: a personal single-use coupon in each reminder. OFF until the owner turns it
 *    on; type / value / cap / minimum / validity default to PLACEHOLDERS (10 % off, at most
 *    ৳300, no minimum, 14 days) that are only suggestions on the form.
 *
 * Not cached (see MessagingSettings: a long-running worker must see a change at once).
 */
final class AutomationSettings
{
    public const string REVIEW_DELAY_DAYS = 'automation.review_request.delay_days';

    public const string QUIET_FROM = 'automation.quiet_hours.from';

    public const string QUIET_TO = 'automation.quiet_hours.to';

    public const string BIRTHDAY_LEAD_DAYS = 'automation.birthday.lead_days';

    public const string OFFER_ENABLED = 'automation.birthday.offer.enabled';

    public const string OFFER_TYPE = 'automation.birthday.offer.type';

    public const string OFFER_VALUE = 'automation.birthday.offer.value';

    public const string OFFER_MAX_DISCOUNT = 'automation.birthday.offer.max_discount';

    public const string OFFER_MIN_ORDER = 'automation.birthday.offer.min_order_amount';

    public const string OFFER_VALID_DAYS = 'automation.birthday.offer.valid_days';

    public const int DEFAULT_DELAY_DAYS = 3;

    public const string DEFAULT_QUIET_FROM = '21:00';

    public const string DEFAULT_QUIET_TO = '09:00';

    /** PLACEHOLDER (owner decision pending): a week ahead leaves time to order a gift. */
    public const int DEFAULT_LEAD_DAYS = 7;

    public const int MAX_LEAD_DAYS = 30;

    /** PLACEHOLDERS for the offer form — never sent until the owner saves the offer as on. */
    public const string DEFAULT_OFFER_TYPE = 'percent';

    public const string DEFAULT_OFFER_VALUE = '10.00';

    public const string DEFAULT_OFFER_MAX_DISCOUNT = '300.00';

    public const string DEFAULT_OFFER_MIN_ORDER = '0.00';

    public const int DEFAULT_OFFER_VALID_DAYS = 14;

    public const int MAX_OFFER_VALID_DAYS = 90;

    /** Delivered orders older than delay + this are never asked (e.g. on first deploy). */
    public const int REVIEW_LOOKBACK_DAYS = 7;

    public const string BIRTHDAY_SEND_AT = '10:00';

    private const array KEYS = [
        self::REVIEW_DELAY_DAYS, self::QUIET_FROM, self::QUIET_TO, self::BIRTHDAY_LEAD_DAYS,
        self::OFFER_ENABLED, self::OFFER_TYPE, self::OFFER_VALUE, self::OFFER_MAX_DISCOUNT, self::OFFER_MIN_ORDER, self::OFFER_VALID_DAYS,
    ];

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

    /** Days before each child's birthday the reminder goes out (0 = on the day). */
    public function birthdayLeadDays(): int
    {
        $value = $this->stored()[self::BIRTHDAY_LEAD_DAYS] ?? null;

        return is_int($value) && $value >= 0 && $value <= self::MAX_LEAD_DAYS ? $value : self::DEFAULT_LEAD_DAYS;
    }

    /**
     * The offer as saved (placeholders filled in for anything never saved).
     *
     * @return array{enabled: bool, type: 'percent'|'fixed', value: string, max_discount: string|null, min_order_amount: string, valid_days: int, is_placeholder: bool}
     */
    public function birthdayOffer(): array
    {
        $s = $this->stored();
        $type = ($s[self::OFFER_TYPE] ?? null) === 'fixed' ? 'fixed' : self::DEFAULT_OFFER_TYPE;
        $days = $s[self::OFFER_VALID_DAYS] ?? null;
        $max = array_key_exists(self::OFFER_MAX_DISCOUNT, $s) ? $s[self::OFFER_MAX_DISCOUNT] : self::DEFAULT_OFFER_MAX_DISCOUNT;

        return [
            'enabled' => ($s[self::OFFER_ENABLED] ?? false) === true,
            'type' => $type === 'fixed' ? 'fixed' : 'percent',
            'value' => self::money($s[self::OFFER_VALUE] ?? null) ?? self::DEFAULT_OFFER_VALUE,
            'max_discount' => self::money($max),
            'min_order_amount' => self::money($s[self::OFFER_MIN_ORDER] ?? null) ?? self::DEFAULT_OFFER_MIN_ORDER,
            'valid_days' => is_int($days) && $days >= 1 && $days <= self::MAX_OFFER_VALID_DAYS ? $days : self::DEFAULT_OFFER_VALID_DAYS,
            // True until the owner has saved the offer once: the values on screen are suggestions.
            'is_placeholder' => ! array_key_exists(self::OFFER_VALUE, $s),
        ];
    }

    /** The coupon terms for a birthday reminder, or null while the offer is off. */
    public function birthdayCouponTerms(): ?PersonalCouponTerms
    {
        $offer = $this->birthdayOffer();
        if (! $offer['enabled'] || $offer['is_placeholder']) {
            return null;
        }

        return new PersonalCouponTerms(
            type: $offer['type'],
            value: Money::of($offer['value']),
            maxDiscount: $offer['type'] === 'percent' && $offer['max_discount'] !== null ? Money::of($offer['max_discount']) : null,
            minOrderAmount: Money::of($offer['min_order_amount']),
            validDays: $offer['valid_days'],
            prefix: 'BDAY',
            batch: 'birthday-reminder',
            description: 'Birthday reminder offer (automation)',
        );
    }

    /** @return array{review_delay_days: int, quiet_from: string, quiet_to: string, birthday_send_at: string, birthday_lead_days: int, birthday_offer: array{enabled: bool, type: 'percent'|'fixed', value: string, max_discount: string|null, min_order_amount: string, valid_days: int, is_placeholder: bool}} */
    public function adminView(): array
    {
        $quiet = $this->quietHours();

        return [
            'review_delay_days' => $this->reviewDelayDays(),
            'quiet_from' => $quiet['from'],
            'quiet_to' => $quiet['to'],
            'birthday_send_at' => self::BIRTHDAY_SEND_AT,
            'birthday_lead_days' => $this->birthdayLeadDays(),
            'birthday_offer' => $this->birthdayOffer(),
        ];
    }

    private static function money(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{1,12}(\.\d{1,2})?$/', $value) === 1 ? Money::of($value)->toDecimal() : null;
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return Setting::query()
            ->where('group', SettingGroup::Notification->value)
            ->whereIn('key', self::KEYS)
            ->get()
            ->mapWithKeys(static fn (Setting $s): array => [$s->key => $s->value])
            ->all();
    }
}

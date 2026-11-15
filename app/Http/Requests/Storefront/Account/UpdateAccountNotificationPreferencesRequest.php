<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use Closure;
use Illuminate\Validation\Rule;

/**
 * `PUT /storefront/account/notification-preferences` — the signed-in customer turns order updates
 * on or off per messaging channel (the profile page's "Order updates on WhatsApp" and "SMS updates"
 * switches). Allowlisted: channel `whatsapp` or `sms`, category `order_updates`. `security` (sign-in
 * codes) can never be turned off and is refused with a 422; anything else is simply not offered.
 */
final class UpdateAccountNotificationPreferencesRequest extends AccountRequest
{
    /** The channels a customer may switch here. */
    public const array CHANNELS = [NotificationChannel::WhatsApp, NotificationChannel::Sms];

    /** The categories a customer may switch here. */
    public const array CATEGORIES = [NotificationCategory::OrderUpdates];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** One row per channel; a channel left out keeps its current setting. */
            'preferences' => ['required', 'array', 'min:1', 'max:'.count(self::CHANNELS) * count(self::CATEGORIES)],
            /** @var 'whatsapp'|'sms' */
            'preferences.*.channel' => ['required', 'string', 'distinct', Rule::in(array_map(static fn (NotificationChannel $c): string => $c->value, self::CHANNELS))],
            /** @var 'order_updates' Only order updates; `security` is refused (422). */
            'preferences.*.category' => ['required', 'string', self::notSecurity(), Rule::in(array_map(static fn (NotificationCategory $c): string => $c->value, self::CATEGORIES))],
            'preferences.*.enabled' => ['required', 'boolean'],
        ];
    }

    /** @return list<array{channel: NotificationChannel, category: NotificationCategory, enabled: bool}> */
    public function preferences(): array
    {
        $rows = [];
        foreach ((array) $this->input('preferences', []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = [
                'channel' => NotificationChannel::from((string) $row['channel']),
                'category' => NotificationCategory::from((string) $row['category']),
                'enabled' => filter_var($row['enabled'], FILTER_VALIDATE_BOOL),
            ];
        }

        return $rows;
    }

    private static function notSecurity(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === NotificationCategory::Security->value) {
                $fail((string) __('account.notifications.security_locked'));
            }
        };
    }
}

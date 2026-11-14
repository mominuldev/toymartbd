<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Services;

use App\Domain\Inbox\Enums\InboxChannel;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Inbox channel credentials. Read from `settings` first (keys `inbox.{channel}.{name}`, group
 * notification; secrets saved with `is_encrypted`, rule 20) and fall back to config/inbox.php
 * (env). NOT cached: a credential fixed in the admin applies to the next send without a
 * worker restart (the same reasoning as Notification\Services\MessagingSettings).
 *
 * There is no admin screen that writes these yet — Integrations → Messaging belongs to
 * another screen (docs/api/inbox.md "Credentials"). Secrets never leave this class except
 * to the driver that uses them; {@see self::isSet()} is all anyone else learns.
 */
final class InboxChannelSettings
{
    /** @var array<string, list<string>> */
    public const array KEYS = [
        'whatsapp' => ['driver', 'phone_number_id', 'access_token', 'app_secret', 'verify_token'],
        'messenger' => ['driver', 'page_id', 'page_access_token', 'app_secret', 'verify_token'],
        'live_chat' => ['driver'],
    ];

    /** Stored with `is_encrypted`. */
    public const array SECRETS = ['access_token', 'page_access_token', 'app_secret', 'verify_token'];

    public function get(InboxChannel $channel, string $name): ?string
    {
        $key = 'inbox.'.$channel->value.'.'.$name;

        try {
            $stored = Setting::query()->where('group', SettingGroup::Notification->value)->find($key)?->value;
        } catch (DecryptException) {
            $stored = null; // APP_KEY rotated: fall back to env rather than crash a send.
        }

        if (is_string($stored) && trim($stored) !== '') {
            return trim($stored);
        }

        $fallback = config($key);

        return is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : null;
    }

    public function isSet(InboxChannel $channel, string $name): bool
    {
        return $this->get($channel, $name) !== null;
    }

    public function driver(InboxChannel $channel): string
    {
        return $this->get($channel, 'driver') ?? 'log';
    }
}

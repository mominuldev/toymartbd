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
 * Written by Admin → Integrations → Messaging (`PUT /settings/inbox-channels`,
 * {@see \App\Domain\Inbox\Actions\UpdateInboxChannelSettings}). Secrets never leave this
 * class except to the driver that uses them; {@see self::isSet()}, {@see self::source()} and
 * {@see self::hint()} (last 4 characters at most) are all anyone else learns.
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

    /** Drivers selectable per channel: `log` sends nothing; the other is the Meta driver. */
    public const array DRIVERS = [
        'whatsapp' => ['log', 'cloud'],
        'messenger' => ['log', 'messenger'],
    ];

    /** The non-secret account id Meta gives each channel. */
    public const array ACCOUNT_ID = ['whatsapp' => 'phone_number_id', 'messenger' => 'page_id'];

    /** The token the Meta driver sends with. */
    public const array SEND_TOKEN = ['whatsapp' => 'access_token', 'messenger' => 'page_access_token'];

    public static function key(InboxChannel $channel, string $name): string
    {
        return 'inbox.'.$channel->value.'.'.$name;
    }

    public function get(InboxChannel $channel, string $name): ?string
    {
        return $this->stored($channel, $name) ?? $this->fallback($channel, $name);
    }

    /** The value saved in `settings` (not env), trimmed; null when none or undecryptable. */
    public function stored(InboxChannel $channel, string $name): ?string
    {
        try {
            $stored = Setting::query()->where('group', SettingGroup::Notification->value)->find(self::key($channel, $name))?->value;
        } catch (DecryptException) {
            $stored = null; // APP_KEY rotated: fall back to env rather than crash a send.
        }

        return is_string($stored) && trim($stored) !== '' ? trim($stored) : null;
    }

    /** The env (config/inbox.php) value, trimmed; null when unset. */
    public function fallback(InboxChannel $channel, string $name): ?string
    {
        $fallback = config(self::key($channel, $name));

        return is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : null;
    }

    /** Where the effective value comes from: `settings`, `env`, or null when unset. */
    public function source(InboxChannel $channel, string $name): ?string
    {
        return match (true) {
            $this->stored($channel, $name) !== null => 'settings',
            $this->fallback($channel, $name) !== null => 'env',
            default => null,
        };
    }

    /**
     * "••••1234": the last four characters of the effective secret, only when it is long
     * enough (12+) that four characters give nothing useful away; otherwise "••••". Never more.
     */
    public function hint(InboxChannel $channel, string $name): ?string
    {
        $secret = $this->get($channel, $name);
        if ($secret === null) {
            return null;
        }

        return mb_strlen($secret) >= 12 ? '••••'.mb_substr($secret, -4) : '••••';
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

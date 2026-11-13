<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

/**
 * The messaging integration settings (owner request 2026-11-13: "The SMS credential I want
 * to input in the settings, not env file"), edited at Admin → Integrations → Messaging.
 *
 * Every value is read from `settings` (group `notification`, keys `messaging.*`) FIRST and
 * falls back to config/notifications.php (i.e. env) when nobody has saved it — so env
 * still works for CI, tests and deployments that set it before this screen existed.
 * The API key and secret are stored with `is_encrypted` (rule 20) and never leave this
 * class except to the driver that sends; {@see self::secretHint()} is the only view of
 * them anyone else gets.
 *
 * NO CACHING, on purpose: every call reads the table. A queue worker is a long-running
 * process, and a credential the owner fixes in the admin must apply to the very next
 * send without a restart. That is a few primary-key-sized reads per SMS — nothing next
 * to the HTTP call it precedes. Do not memoise values on this object or bind it as a
 * singleton.
 *
 * `whatsapp_enabled` defaults to FALSE (config `notifications.whatsapp.enabled`, env
 * WHATSAPP_ENABLED): no WhatsApp Business account exists yet, and with the `log` WhatsApp
 * driver a "WhatsApp first" message is accepted by the log and the SMS fallback never
 * fires — the customer would get nothing. Turn it on only once a real WhatsApp driver is
 * live.
 */
final class MessagingSettings
{
    public const string PREFIX = 'messaging.';

    /** SMS drivers selectable in the admin. `sms_net_bd` stays env-only (UNVERIFIED skeleton). */
    public const array SMS_DRIVERS = ['log', 'null', 'reve'];

    public const string SMS_DRIVER = 'messaging.sms_driver';

    public const string REVE_BASE_URL = 'messaging.reve.base_url';

    public const string REVE_API_KEY = 'messaging.reve.api_key';

    public const string REVE_SECRET_KEY = 'messaging.reve.secret_key';

    public const string REVE_SENDER_ID = 'messaging.reve.sender_id';

    public const string SMS_COST_PER_SEGMENT = 'messaging.sms_cost_per_segment';

    public const string WHATSAPP_ENABLED = 'messaging.whatsapp_enabled';

    /** Stored encrypted; never returned by any API (only set/unset and a 4-char hint). */
    public const array SECRET_KEYS = [self::REVE_API_KEY, self::REVE_SECRET_KEY];

    /** Setting key → config key it falls back to. */
    public const array FALLBACKS = [
        self::SMS_DRIVER => 'notifications.sms.driver',
        self::REVE_BASE_URL => 'notifications.sms.drivers.reve.base_url',
        self::REVE_API_KEY => 'notifications.sms.drivers.reve.api_key',
        self::REVE_SECRET_KEY => 'notifications.sms.drivers.reve.secret_key',
        self::REVE_SENDER_ID => 'notifications.sms.drivers.reve.sender_id',
        self::SMS_COST_PER_SEGMENT => 'notifications.sms.cost_per_segment',
        self::WHATSAPP_ENABLED => 'notifications.whatsapp.enabled',
    ];

    public function smsDriver(): string
    {
        $driver = $this->value(self::SMS_DRIVER);

        return is_string($driver) && $driver !== '' ? $driver : 'log';
    }

    /**
     * Everything the REVE driver needs. Missing values are null; the driver refuses to send.
     *
     * @return array{base_url: ?string, api_key: ?string, secret_key: ?string, sender_id: ?string, timeout: int}
     */
    public function reve(): array
    {
        $values = $this->values();

        return [
            'base_url' => $this->string(self::REVE_BASE_URL, $values),
            'api_key' => $this->string(self::REVE_API_KEY, $values),
            'secret_key' => $this->string(self::REVE_SECRET_KEY, $values),
            'sender_id' => $this->string(self::REVE_SENDER_ID, $values),
            // Operational, not a credential: stays config-only.
            'timeout' => (int) config('notifications.sms.drivers.reve.timeout', 10),
        ];
    }

    /** Taka per SMS segment; default the owner's REVE rate ৳0.26 (2026-11-13, config/notifications.php). */
    public function costPerSegment(): Money
    {
        $rate = $this->value(self::SMS_COST_PER_SEGMENT);

        return Money::of(is_string($rate) || is_int($rate) ? $rate : '0.00');
    }

    public function whatsappEnabled(): bool
    {
        $value = $this->value(self::WHATSAPP_ENABLED);

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /** Is the effective secret saved here (`settings`), in env (`env`), or nowhere (null)? */
    public function secretSource(string $key): ?string
    {
        $stored = $this->stored()[$key] ?? null;
        if (is_string($stored) && $stored !== '') {
            return 'settings';
        }

        $fallback = config(self::FALLBACKS[$key]);

        return is_string($fallback) && $fallback !== '' ? 'env' : null;
    }

    /**
     * "••••1234": the last four characters of the effective secret, and only when it is
     * long enough that four characters give nothing useful away. Never more.
     */
    public function secretHint(string $key): ?string
    {
        $secret = $this->string($key, $this->values());
        if ($secret === null) {
            return null;
        }

        return mb_strlen($secret) >= 12 ? '••••'.mb_substr($secret, -4) : '••••';
    }

    /** Where each non-secret value currently comes from (`settings` or `env`). */
    public function source(string $key): string
    {
        $stored = $this->stored()[$key] ?? null;

        return $stored !== null && $stored !== '' ? 'settings' : 'env';
    }

    /**
     * The admin screen's view: effective non-secret values, and for each secret ONLY
     * whether it is set, where from, and a last-4 hint. The secret values never appear.
     *
     * @return array{sms_driver: string, reve_base_url: ?string, reve_sender_id: ?string, sms_cost_per_segment: string, whatsapp_enabled: bool, reve_api_key_set: bool, reve_api_key_hint: ?string, reve_api_key_source: ?string, reve_secret_key_set: bool, reve_secret_key_hint: ?string, reve_secret_key_source: ?string, sources: array<string, string>}
     */
    public function adminView(): array
    {
        $values = $this->values();
        $apiSource = $this->secretSource(self::REVE_API_KEY);
        $secretSource = $this->secretSource(self::REVE_SECRET_KEY);

        return [
            'sms_driver' => $this->smsDriver(),
            'reve_base_url' => $this->string(self::REVE_BASE_URL, $values),
            'reve_sender_id' => $this->string(self::REVE_SENDER_ID, $values),
            'sms_cost_per_segment' => $this->costPerSegment()->toDecimal(),
            'whatsapp_enabled' => $this->whatsappEnabled(),
            'reve_api_key_set' => $apiSource !== null,
            'reve_api_key_hint' => $this->secretHint(self::REVE_API_KEY),
            'reve_api_key_source' => $apiSource,
            'reve_secret_key_set' => $secretSource !== null,
            'reve_secret_key_hint' => $this->secretHint(self::REVE_SECRET_KEY),
            'reve_secret_key_source' => $secretSource,
            'sources' => [
                'sms_driver' => $this->source(self::SMS_DRIVER),
                'reve_base_url' => $this->source(self::REVE_BASE_URL),
                'reve_sender_id' => $this->source(self::REVE_SENDER_ID),
                'sms_cost_per_segment' => $this->source(self::SMS_COST_PER_SEGMENT),
                'whatsapp_enabled' => $this->source(self::WHATSAPP_ENABLED),
            ],
        ];
    }

    private function value(string $key): mixed
    {
        return $this->values()[$key] ?? null;
    }

    /** @param  array<string, mixed>  $values */
    private function string(string $key, array $values): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Effective values: a saved, non-empty setting wins; otherwise config.
     *
     * @return array<string, mixed>
     */
    private function values(): array
    {
        $stored = $this->stored();
        $values = [];
        foreach (self::FALLBACKS as $key => $configKey) {
            $saved = $stored[$key] ?? null;
            $values[$key] = $saved !== null && $saved !== '' ? $saved : config($configKey);
        }

        return $values;
    }

    /**
     * Fresh from the table on every call (see the class note).
     *
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $values = [];
        $rows = Setting::query()
            ->where('group', SettingGroup::Notification->value)
            ->whereIn('key', array_keys(self::FALLBACKS))
            ->get();

        foreach ($rows as $setting) {
            try {
                // An encrypted row decrypts on read, so a rotated APP_KEY surfaces here.
                $values[$setting->key] = $setting->getAttribute('value');
            } catch (DecryptException) {
                // APP_KEY rotated without re-saving: treat as unset, say which key, never the value.
                Log::warning('messaging.settings.undecryptable', ['key' => $setting->key]);
                $values[$setting->key] = null;
            }
        }

        return $values;
    }
}

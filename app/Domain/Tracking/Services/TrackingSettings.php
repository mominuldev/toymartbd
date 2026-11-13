<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Services;

use App\Domain\Tracking\Enums\PurchaseTrigger;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

/**
 * Server-side tracking settings (PRD K-01..K-03), edited at Admin → Integrations → Tracking.
 *
 * Stored exactly like {@see \App\Domain\Notification\Services\MessagingSettings}: every value
 * is read from `settings` (group `tracking`, keys `tracking.*`) FIRST and falls back to
 * config/tracking.php (env) when nobody has saved it. The GA4 API secret and the Meta access
 * token are stored with `is_encrypted` (rule 20) and leave this class only towards the
 * driver that sends; {@see self::secretHint()} is the only view anyone else gets, and
 * {@see self::publicConfig()} — what the storefront reads — never contains either.
 *
 * NO CACHING, on purpose (same reason as MessagingSettings): a queue worker is long-lived
 * and a credential fixed in the admin must apply to the very next send. Do not memoise
 * values on this object or bind it as a singleton.
 */
final class TrackingSettings
{
    public const string PREFIX = 'tracking.';

    public const string SERVER_SIDE_ENABLED = 'tracking.server_side_enabled';

    public const string GA4_ENABLED = 'tracking.ga4.enabled';

    public const string GA4_MEASUREMENT_ID = 'tracking.ga4.measurement_id';

    public const string GA4_API_SECRET = 'tracking.ga4.api_secret';

    public const string META_ENABLED = 'tracking.meta.enabled';

    public const string META_PIXEL_ID = 'tracking.meta.pixel_id';

    public const string META_ACCESS_TOKEN = 'tracking.meta.access_token';

    public const string META_TEST_EVENT_CODE = 'tracking.meta.test_event_code';

    public const string GTM_CONTAINER_ID = 'tracking.gtm_container_id';

    public const string PURCHASE_TRIGGER = 'tracking.purchase_trigger';

    public const string CONSENT_MODE = 'tracking.consent_mode';

    public const string CONSENT_POLICY_VERSION = 'tracking.consent_policy_version';

    public const array CONSENT_MODES = ['opt_in', 'notice'];

    /** Stored encrypted; never returned by any API (only set/unset, source and a 4-char hint). */
    public const array SECRET_KEYS = [self::GA4_API_SECRET, self::META_ACCESS_TOKEN];

    /** Setting key → config key it falls back to. Same names, so `tracking.x` ↔ config('tracking.x'). */
    public const array FALLBACKS = [
        self::SERVER_SIDE_ENABLED => 'tracking.server_side_enabled',
        self::GA4_ENABLED => 'tracking.ga4.enabled',
        self::GA4_MEASUREMENT_ID => 'tracking.ga4.measurement_id',
        self::GA4_API_SECRET => 'tracking.ga4.api_secret',
        self::META_ENABLED => 'tracking.meta.enabled',
        self::META_PIXEL_ID => 'tracking.meta.pixel_id',
        self::META_ACCESS_TOKEN => 'tracking.meta.access_token',
        self::META_TEST_EVENT_CODE => 'tracking.meta.test_event_code',
        self::GTM_CONTAINER_ID => 'tracking.gtm_container_id',
        self::PURCHASE_TRIGGER => 'tracking.purchase_trigger',
        self::CONSENT_MODE => 'tracking.consent_mode',
        self::CONSENT_POLICY_VERSION => 'tracking.consent_policy_version',
    ];

    public function serverSideEnabled(): bool
    {
        return self::bool($this->value(self::SERVER_SIDE_ENABLED));
    }

    public function ga4MeasurementId(): ?string
    {
        return $this->string(self::GA4_MEASUREMENT_ID, $this->values());
    }

    /** Decrypted. Only the GA4 driver may call this. */
    public function ga4ApiSecret(): ?string
    {
        return $this->string(self::GA4_API_SECRET, $this->values());
    }

    public function ga4Enabled(): bool
    {
        return self::bool($this->value(self::GA4_ENABLED));
    }

    public function metaPixelId(): ?string
    {
        return $this->string(self::META_PIXEL_ID, $this->values());
    }

    /** Decrypted. Only the Meta driver may call this. */
    public function metaAccessToken(): ?string
    {
        return $this->string(self::META_ACCESS_TOKEN, $this->values());
    }

    public function metaTestEventCode(): ?string
    {
        return $this->string(self::META_TEST_EVENT_CODE, $this->values());
    }

    public function metaEnabled(): bool
    {
        return self::bool($this->value(self::META_ENABLED));
    }

    /** Operational, config-only — never editable in the admin. */
    public function metaGraphVersion(): string
    {
        $version = config('tracking.meta.graph_version');

        return is_string($version) && $version !== '' ? $version : 'v27.0';
    }

    public function gtmContainerId(): ?string
    {
        return $this->string(self::GTM_CONTAINER_ID, $this->values());
    }

    public function purchaseTrigger(): PurchaseTrigger
    {
        $value = $this->value(self::PURCHASE_TRIGGER);

        return (is_string($value) ? PurchaseTrigger::tryFrom($value) : null) ?? PurchaseTrigger::OrderPlaced;
    }

    /** `opt_in` (default) | `notice`. */
    public function consentMode(): string
    {
        $value = $this->value(self::CONSENT_MODE);

        return is_string($value) && in_array($value, self::CONSENT_MODES, true) ? $value : 'opt_in';
    }

    public function consentPolicyVersion(): ?string
    {
        return $this->string(self::CONSENT_POLICY_VERSION, $this->values());
    }

    /**
     * What `GET /storefront/tracking/config` returns: public ids and flags ONLY. A pixel id
     * and a measurement id are public by nature (they ship in every page's HTML); the GA4
     * API secret, the Meta access token and the Meta test-event code never appear here.
     *
     * @return array{ga4_measurement_id: ?string, meta_pixel_id: ?string, gtm_container_id: ?string, consent_mode: string, consent_policy_version: ?string, purchase_trigger: string, enabled: array{ga4: bool, meta: bool}}
     */
    public function publicConfig(): array
    {
        $ga4 = $this->ga4Enabled() && $this->ga4MeasurementId() !== null;
        $meta = $this->metaEnabled() && $this->metaPixelId() !== null;

        return [
            'ga4_measurement_id' => $ga4 ? $this->ga4MeasurementId() : null,
            'meta_pixel_id' => $meta ? $this->metaPixelId() : null,
            'gtm_container_id' => $this->gtmContainerId(),
            'consent_mode' => $this->consentMode(),
            'consent_policy_version' => $this->consentPolicyVersion(),
            'purchase_trigger' => $this->purchaseTrigger()->value,
            'enabled' => ['ga4' => $ga4, 'meta' => $meta],
        ];
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

    /** "••••1234": the last four characters, only when the secret is long enough to spare them. */
    public function secretHint(string $key): ?string
    {
        $secret = $this->string($key, $this->values());
        if ($secret === null) {
            return null;
        }

        return mb_strlen($secret) >= 12 ? '••••'.mb_substr($secret, -4) : '••••';
    }

    /** Where a non-secret value currently comes from (`settings` or `env`). */
    public function source(string $key): string
    {
        $stored = $this->stored()[$key] ?? null;

        return $stored !== null && $stored !== '' ? 'settings' : 'env';
    }

    /**
     * The admin screen's view: effective non-secret values, and for each secret ONLY whether
     * it is set, where from, and a last-4 hint. The secret values never appear.
     *
     * @return array<string, mixed>
     */
    public function adminView(): array
    {
        $sources = [];
        foreach (array_diff(array_keys(self::FALLBACKS), self::SECRET_KEYS) as $key) {
            $sources[self::short($key)] = $this->source($key);
        }
        $ga4Source = $this->secretSource(self::GA4_API_SECRET);
        $metaSource = $this->secretSource(self::META_ACCESS_TOKEN);

        return [
            'server_side_enabled' => $this->serverSideEnabled(),
            'ga4_enabled' => $this->ga4Enabled(),
            'ga4_measurement_id' => $this->ga4MeasurementId(),
            'ga4_api_secret_set' => $ga4Source !== null,
            'ga4_api_secret_hint' => $this->secretHint(self::GA4_API_SECRET),
            'ga4_api_secret_source' => $ga4Source,
            'meta_enabled' => $this->metaEnabled(),
            'meta_pixel_id' => $this->metaPixelId(),
            'meta_access_token_set' => $metaSource !== null,
            'meta_access_token_hint' => $this->secretHint(self::META_ACCESS_TOKEN),
            'meta_access_token_source' => $metaSource,
            'meta_test_event_code' => $this->metaTestEventCode(),
            'meta_graph_version' => $this->metaGraphVersion(),
            'gtm_container_id' => $this->gtmContainerId(),
            'purchase_trigger' => $this->purchaseTrigger()->value,
            'consent_mode' => $this->consentMode(),
            'consent_policy_version' => $this->consentPolicyVersion(),
            'sources' => $sources,
        ];
    }

    /** `tracking.ga4.api_secret` → `ga4_api_secret`. */
    public static function short(string $key): string
    {
        return str_replace('.', '_', substr($key, strlen(self::PREFIX)));
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private function value(string $key): mixed
    {
        return $this->values()[$key] ?? null;
    }

    /** @param  array<string, mixed>  $values */
    private function string(string $key, array $values): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
            ->where('group', SettingGroup::Tracking->value)
            ->whereIn('key', array_keys(self::FALLBACKS))
            ->get();

        foreach ($rows as $setting) {
            try {
                $values[$setting->key] = $setting->getAttribute('value');
            } catch (DecryptException) {
                // APP_KEY rotated without re-saving: treat as unset, name the key, never the value.
                Log::warning('tracking.settings.undecryptable', ['key' => $setting->key]);
                $values[$setting->key] = null;
            }
        }

        return $values;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Actions;

use App\Domain\Tracking\DTOs\TrackingSettingsData;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Saves Admin → Integrations → Tracking (PRD K-01) the way UpdateMessagingSettings saves
 * messaging: secrets with `is_encrypted` (rule 20), one `audit_sensitive` row
 * (`credential_changed`, scope `tracking`) naming what changed — for a secret only the words
 * "changed" / "cleared", never the value or a hint. Then revalidates the storefront's
 * `tracking-config` tag (queued after commit) when anything changed. Nothing here logs.
 */
final readonly class UpdateTrackingSettings
{
    public function __construct(private RevalidateTrackingConfig $revalidate) {}

    public function handle(TrackingSettingsData $data, ?int $userId): void
    {
        DB::transaction(function () use ($data, $userId): void {
            $before = $this->plainValues();

            $plain = [
                TrackingSettings::SERVER_SIDE_ENABLED => $data->serverSideEnabled,
                TrackingSettings::GA4_ENABLED => $data->ga4Enabled,
                TrackingSettings::GA4_MEASUREMENT_ID => self::blank($data->ga4MeasurementId),
                TrackingSettings::META_ENABLED => $data->metaEnabled,
                TrackingSettings::META_PIXEL_ID => self::blank($data->metaPixelId),
                TrackingSettings::META_TEST_EVENT_CODE => self::blank($data->metaTestEventCode),
                TrackingSettings::GTM_CONTAINER_ID => self::blank($data->gtmContainerId),
                TrackingSettings::PURCHASE_TRIGGER => $data->purchaseTrigger->value,
                TrackingSettings::CONSENT_MODE => $data->consentMode,
                TrackingSettings::CONSENT_POLICY_VERSION => self::blank($data->consentPolicyVersion),
            ];

            $changedBefore = [];
            $changedAfter = [];
            foreach ($plain as $key => $value) {
                $old = $before[$key] ?? null;
                if ($old === $value) {
                    continue;
                }
                if ($value === null) {
                    Setting::query()->whereKey($key)->delete();
                } else {
                    Setting::put($key, $value, SettingGroup::Tracking, false, $userId);
                }
                $changedBefore[TrackingSettings::short($key)] = $old;
                $changedAfter[TrackingSettings::short($key)] = $value;
            }

            foreach ([
                TrackingSettings::GA4_API_SECRET => [$data->ga4ApiSecret, $data->clearGa4ApiSecret],
                TrackingSettings::META_ACCESS_TOKEN => [$data->metaAccessToken, $data->clearMetaAccessToken],
            ] as $key => [$value, $clear]) {
                if ($clear) {
                    if (Setting::query()->whereKey($key)->delete() > 0) {
                        $changedAfter[TrackingSettings::short($key)] = 'cleared';
                    }
                } elseif ($value !== null && trim($value) !== '') {
                    Setting::put($key, trim($value), SettingGroup::Tracking, true, $userId);
                    $changedAfter[TrackingSettings::short($key)] = 'changed';
                }
            }

            if ($changedAfter === []) {
                return;
            }

            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::CredentialChanged,
                'before' => ['scope' => 'tracking'] + $changedBefore,
                'after' => ['scope' => 'tracking'] + $changedAfter,
            ]);

            // Queued with afterCommit: released only once this transaction commits (rule 4).
            $this->revalidate->handle();
        });
    }

    private static function blank(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    /**
     * Saved non-secret values (not the env fallbacks) — what this save is replacing.
     *
     * @return array<string, mixed>
     */
    private function plainValues(): array
    {
        $values = [];
        $keys = array_diff(array_keys(TrackingSettings::FALLBACKS), TrackingSettings::SECRET_KEYS);
        foreach (Setting::query()->whereIn('key', $keys)->lockForUpdate()->get() as $setting) {
            $values[$setting->key] = $setting->getAttribute('value');
        }

        return $values;
    }
}

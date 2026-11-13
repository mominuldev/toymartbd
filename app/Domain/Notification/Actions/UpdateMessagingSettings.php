<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\DTOs\MessagingSettingsData;
use App\Domain\Notification\Services\MessagingSettings;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Saves Admin → Integrations → Messaging (owner request 2026-11-13) and writes one
 * `audit_sensitive` row (`credential_changed`) naming what changed and who changed it.
 *
 * Secrets are written with `is_encrypted` (rule 20). The audit row records non-secret
 * values before/after, but for a secret only the words "changed" / "cleared" — never the
 * value, never a hint of it. Nothing here logs.
 */
final readonly class UpdateMessagingSettings
{
    public function handle(MessagingSettingsData $data, ?int $userId): void
    {
        DB::transaction(function () use ($data, $userId): void {
            $before = $this->plainValues();

            $plain = [
                MessagingSettings::SMS_DRIVER => $data->smsDriver,
                MessagingSettings::REVE_BASE_URL => $data->reveBaseUrl,
                MessagingSettings::REVE_SENDER_ID => $data->reveSenderId,
                MessagingSettings::SMS_COST_PER_SEGMENT => Money::of($data->costPerSegment)->toDecimal(),
                MessagingSettings::WHATSAPP_ENABLED => $data->whatsappEnabled,
            ];

            $after = [];
            foreach ($plain as $key => $value) {
                if ($value === null || $value === '') {
                    Setting::query()->whereKey($key)->delete();
                } elseif (($before[$key] ?? null) !== $value) {
                    Setting::put($key, $value, SettingGroup::Notification, false, $userId);
                }
                $after[$key] = $value === '' ? null : $value;
            }

            $secretChanges = [];
            foreach ([
                MessagingSettings::REVE_API_KEY => [$data->reveApiKey, $data->clearReveApiKey],
                MessagingSettings::REVE_SECRET_KEY => [$data->reveSecretKey, $data->clearReveSecretKey],
            ] as $key => [$value, $clear]) {
                if ($clear) {
                    if (Setting::query()->whereKey($key)->delete() > 0) {
                        $secretChanges[$key] = 'cleared';
                    }
                } elseif ($value !== null && $value !== '') {
                    Setting::put($key, $value, SettingGroup::Notification, true, $userId);
                    $secretChanges[$key] = 'changed';
                }
            }

            $changedBefore = [];
            $changedAfter = [];
            foreach ($after as $key => $value) {
                if (($before[$key] ?? null) !== $value) {
                    $changedBefore[self::short($key)] = $before[$key] ?? null;
                    $changedAfter[self::short($key)] = $value;
                }
            }
            foreach ($secretChanges as $key => $what) {
                $changedAfter[self::short($key)] = $what;
            }

            if ($changedAfter === []) {
                return;
            }

            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::CredentialChanged,
                'before' => ['scope' => 'messaging'] + $changedBefore,
                'after' => ['scope' => 'messaging'] + $changedAfter,
            ]);
        });
    }

    /**
     * Saved non-secret values (not the env fallbacks) — what this save is replacing.
     *
     * @return array<string, mixed>
     */
    private function plainValues(): array
    {
        $values = [];
        $keys = array_diff(array_keys(MessagingSettings::FALLBACKS), MessagingSettings::SECRET_KEYS);
        foreach (Setting::query()->whereIn('key', $keys)->lockForUpdate()->get() as $setting) {
            $values[$setting->key] = $setting->value;
        }

        return $values;
    }

    private static function short(string $key): string
    {
        return str_replace(['messaging.reve.', 'messaging.'], ['reve_', ''], $key);
    }
}

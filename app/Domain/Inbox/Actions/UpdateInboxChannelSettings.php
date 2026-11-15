<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\DTOs\InboxChannelCredentials;
use App\Domain\Inbox\Services\InboxChannelSettings;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Saves the WhatsApp / Messenger inbox credentials (Admin → Integrations → Messaging) into
 * `settings` (`inbox.{channel}.*`, group notification) and writes one `audit_sensitive` row
 * (`credential_changed`, scope `inbox_{channel}`) per channel that changed.
 *
 * Secrets are written with `is_encrypted` (rule 20). The audit row records the driver and the
 * account id before/after, but for a secret only "changed" / "cleared" — never the value, never
 * a hint of it. Nothing here logs. Saving never marks a channel verified: the drivers report
 * `isVerified() = false` until someone has watched a real message arrive (docs/api/inbox.md).
 */
final readonly class UpdateInboxChannelSettings
{
    /** @param  list<InboxChannelCredentials>  $channels */
    public function handle(array $channels, ?int $userId): void
    {
        DB::transaction(function () use ($channels, $userId): void {
            foreach ($channels as $data) {
                $this->saveChannel($data, $userId);
            }
        });
    }

    private function saveChannel(InboxChannelCredentials $data, ?int $userId): void
    {
        $channel = $data->channel;
        $idName = InboxChannelSettings::ACCOUNT_ID[$channel->value];

        $plain = ['driver' => $data->driver, $idName => $data->accountId];
        foreach (InboxChannelSettings::EXTRA[$channel->value] ?? [] as $name) {
            if (array_key_exists($name, $data->extra)) {
                $plain[$name] = $data->extra[$name];
            }
        }
        $plainKeys = array_map(fn (string $name): string => InboxChannelSettings::key($channel, $name), array_keys($plain));

        $before = [];
        foreach (Setting::query()->whereIn('key', $plainKeys)->lockForUpdate()->get() as $setting) {
            $before[$setting->key] = $setting->value;
        }

        $changedBefore = [];
        $changedAfter = [];
        foreach ($plain as $name => $value) {
            $key = InboxChannelSettings::key($channel, $name);
            $old = $before[$key] ?? null;
            if ($value === null || $value === '') {
                Setting::query()->whereKey($key)->delete();
                $value = null;
            } elseif ($old !== $value) {
                Setting::put($key, $value, SettingGroup::Notification, false, $userId);
            }
            if ($old !== $value) {
                $changedBefore[$name] = $old;
                $changedAfter[$name] = $value;
            }
        }

        foreach (InboxChannelSettings::KEYS[$channel->value] as $name) {
            if (! in_array($name, InboxChannelSettings::SECRETS, true)) {
                continue;
            }
            $key = InboxChannelSettings::key($channel, $name);
            if (in_array($name, $data->clear, true)) {
                if (Setting::query()->whereKey($key)->delete() > 0) {
                    $changedAfter[$name] = 'cleared';
                }
            } elseif (($data->secrets[$name] ?? '') !== '') {
                Setting::put($key, $data->secrets[$name], SettingGroup::Notification, true, $userId);
                $changedAfter[$name] = 'changed';
            }
        }

        if ($changedAfter === []) {
            return;
        }

        $scope = ['scope' => 'inbox_'.$channel->value];
        AuditSensitive::query()->create([
            'user_id' => $userId,
            'action' => AuditAction::CredentialChanged,
            'before' => $scope + $changedBefore,
            'after' => $scope + $changedAfter,
        ]);
    }
}

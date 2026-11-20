<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Services\InboxChannelSettings;
use App\Domain\Inbox\Services\MetaPageLogin;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * "Disconnect" on a channel card. Removes the SAVED account and send token (Messenger: the Page,
 * its token, name and connection stamp; WhatsApp: the phone number id, Business Account id and
 * access token), the last connection test, and switches replies back to `log`. The webhook keys
 * (app secret, verify token) and the Meta App ID stay: they belong to the Meta app, not to the
 * Page or number. Values that come from env are not touched — the card then still shows them.
 *
 * Messenger first asks Meta to stop sending the Page's chats (`DELETE /{page-id}/subscribed_apps`
 * with the app token, best effort, before the transaction — rule 4). WhatsApp is not
 * deregistered: that would take the number off the Cloud API for everyone (Meta allows 10
 * deregistrations per 72 h) and is not undone by connecting again. Conversations stay.
 */
final readonly class DisconnectInboxChannel
{
    public function __construct(private InboxChannelSettings $settings, private MetaPageLogin $login) {}

    /** @return array{unsubscribed: bool|null} null = not applicable (WhatsApp, or no saved Page) */
    public function handle(InboxChannel $channel, ?int $userId): array
    {
        if (! $channel->receivesMetaWebhooks()) {
            throw new InvalidArgumentException('Only WhatsApp and Messenger can be disconnected.');
        }

        $unsubscribed = null;
        $pageId = $channel === InboxChannel::Messenger ? $this->settings->stored($channel, 'page_id') : null;
        if ($pageId !== null) {
            $unsubscribed = $this->login->unsubscribe($pageId);
        }

        $names = $channel === InboxChannel::Messenger
            ? ['page_id', 'page_access_token', ...InboxChannelSettings::CONNECTION, InboxChannelSettings::LAST_CHECK]
            : ['phone_number_id', 'business_account_id', 'access_token', InboxChannelSettings::LAST_CHECK];

        DB::transaction(function () use ($channel, $names, $userId, $unsubscribed): void {
            $keys = array_map(static fn (string $name): string => InboxChannelSettings::key($channel, $name), [...$names, 'driver']);
            $before = [];
            foreach (Setting::query()->whereIn('key', $keys)->lockForUpdate()->get() as $setting) {
                $before[$setting->key] = $setting->value;
            }

            $removed = Setting::query()->whereIn('key', array_map(static fn (string $name): string => InboxChannelSettings::key($channel, $name), $names))->delete();
            Setting::put(InboxChannelSettings::key($channel, 'driver'), 'log', SettingGroup::Notification, false, $userId);

            if ($removed === 0 && ($before[InboxChannelSettings::key($channel, 'driver')] ?? 'log') === 'log') {
                return; // Nothing was saved: nothing to audit.
            }

            $idName = InboxChannelSettings::ACCOUNT_ID[$channel->value];
            $scope = ['scope' => 'inbox_'.$channel->value];
            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::CredentialChanged,
                'before' => $scope + [
                    $idName => $before[InboxChannelSettings::key($channel, $idName)] ?? null,
                    'driver' => $before[InboxChannelSettings::key($channel, 'driver')] ?? null,
                ],
                'after' => $scope + [
                    $idName => null,
                    'driver' => 'log',
                    InboxChannelSettings::SEND_TOKEN[$channel->value] => 'cleared',
                    'via' => 'disconnect',
                    'unsubscribed' => $unsubscribed,
                ],
            ]);
        });

        return ['unsubscribed' => $unsubscribed];
    }
}

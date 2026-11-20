<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Exceptions\MetaConnectionFailed;
use App\Domain\Inbox\Services\InboxChannelSettings;
use App\Domain\Inbox\Services\MetaPageLogin;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Continue with Facebook", step 3: the chosen Page is subscribed to the app's webhook (Graph
 * call, BEFORE the transaction — rule 4), then its id, its Page token (encrypted, rule 20), its
 * name and who connected it when are saved, replies switch to the Messenger driver, and the
 * previous connection test is dropped. One `credential_changed` audit row (scope
 * `inbox_messenger`, `via: facebook_login`) — the token only as "changed".
 *
 * Connecting never marks the channel verified (docs/api/inbox.md).
 */
final readonly class ConnectMessengerPage
{
    public function __construct(private MetaPageLogin $login) {}

    public function handle(string $session, string $pageId, int $userId): void
    {
        $page = $this->page($session, $pageId, $userId);
        if (! $page['can_message']) {
            throw new MetaConnectionFailed('Your Facebook account cannot manage messages on this Page. Ask a Page admin to connect it.', 'no_messaging_task');
        }

        $this->login->subscribe($page['id'], $page['token']);

        DB::transaction(function () use ($page, $userId): void {
            $key = static fn (string $name): string => InboxChannelSettings::key(InboxChannel::Messenger, $name);
            $before = [];
            foreach (Setting::query()->whereIn('key', [$key('page_id'), $key('driver'), $key('page_name')])->lockForUpdate()->get() as $setting) {
                $before[str_replace('inbox.messenger.', '', $setting->key)] = $setting->value;
            }

            Setting::put($key('page_id'), $page['id'], SettingGroup::Notification, false, $userId);
            Setting::put($key('page_access_token'), $page['token'], SettingGroup::Notification, true, $userId);
            Setting::put($key('page_name'), $page['name'], SettingGroup::Notification, false, $userId);
            Setting::put($key('connected_at'), now()->toIso8601String(), SettingGroup::Notification, false, $userId);
            Setting::put($key('connected_by'), (string) $userId, SettingGroup::Notification, false, $userId);
            Setting::put($key('driver'), 'messenger', SettingGroup::Notification, false, $userId);
            Setting::query()->whereKey($key(InboxChannelSettings::LAST_CHECK))->delete();

            $scope = ['scope' => 'inbox_messenger'];
            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::CredentialChanged,
                'before' => $scope + ['page_id' => $before['page_id'] ?? null, 'page_name' => $before['page_name'] ?? null, 'driver' => $before['driver'] ?? null],
                'after' => $scope + ['page_id' => $page['id'], 'page_name' => $page['name'], 'driver' => 'messenger', 'page_access_token' => 'changed', 'via' => 'facebook_login'],
            ]);
        });

        Cache::forget(FinishMessengerLogin::SESSION_KEY.$session);
    }

    /** @return array{id: string, name: string, token: string, can_message: bool} */
    private function page(string $session, string $pageId, int $userId): array
    {
        $raw = Cache::get(FinishMessengerLogin::SESSION_KEY.$session);
        try {
            $data = is_string($raw) ? decrypt($raw) : null;
        } catch (DecryptException) {
            $data = null;
        }
        if (! is_array($data) || ($data['user'] ?? null) !== $userId || ! is_array($data['pages'] ?? null)) {
            throw new MetaConnectionFailed('This Facebook sign-in has expired. Press Continue with Facebook again.', 'session_expired');
        }

        foreach ($data['pages'] as $page) {
            if (is_array($page) && ($page['id'] ?? null) === $pageId) {
                /** @var array{id: string, name: string, token: string, can_message: bool} $page */
                return $page;
            }
        }

        throw new MetaConnectionFailed('That Page was not shared in the Facebook window.', 'unknown_page');
    }
}

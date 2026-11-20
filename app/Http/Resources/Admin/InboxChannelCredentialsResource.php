<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One inbox channel on Admin → Integrations → Messaging. Secrets are NEVER returned — only
 * whether each is set, where it comes from, and a hint of at most its last four characters.
 *
 * @property array{channel: string, driver: string, driver_source: string, account_id: ?string, account_id_source: ?string, business_account_id: ?string, business_account_id_source: ?string, human_agent: bool, human_agent_source: string, send_token: array{set: bool, hint: ?string, source: ?string}, app_secret: array{set: bool, hint: ?string, source: ?string}, verify_token: array{set: bool, hint: ?string, source: ?string}, webhook_url: string, webhook_ready: bool, send_ready: bool, verified: bool, last_check: array<string, mixed>|null, connection: array<string, mixed>, activity: array<string, mixed>, page_name: ?string, connected_at: ?string, connected_by: ?string, app_id: ?string, app_id_source: ?string, login_config_id: ?string, login_config_id_source: ?string, login_ready: bool, oauth_redirect_uri: ?string} $resource
 */
#[SchemaName('InboxChannelCredentials')]
final class InboxChannelCredentialsResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var 'whatsapp'|'messenger' */
            'channel' => $r['channel'],
            /** @var 'log'|'cloud'|'messenger' The configured send driver; `log` sends nothing. */
            'driver' => $r['driver'],
            /** @var 'settings'|'env' */
            'driver_source' => $r['driver_source'],
            /** @var string|null WhatsApp: the phone number id. Messenger: the Page id. */
            'account_id' => $r['account_id'],
            /** @var 'settings'|'env'|null */
            'account_id_source' => $r['account_id_source'],
            /** @var string|null WhatsApp only: the WhatsApp Business Account id ("Sync from Meta"). Null for Messenger. */
            'business_account_id' => $r['business_account_id'],
            /** @var 'settings'|'env'|null */
            'business_account_id_source' => $r['business_account_id_source'],
            /** @var bool Messenger only: reply with the HUMAN_AGENT tag for up to 7 days. Always false for WhatsApp. */
            'human_agent' => $r['human_agent'],
            /** @var 'settings'|'env' */
            'human_agent_source' => $r['human_agent_source'],
            /** @var array{set: bool, hint: string|null, source: 'settings'|'env'|null} WhatsApp access token / Messenger Page access token. Hint "••••1234" at most. */
            'send_token' => $r['send_token'],
            /** @var array{set: bool, hint: string|null, source: 'settings'|'env'|null} Meta App Secret — keys the X-Hub-Signature-256 check. */
            'app_secret' => $r['app_secret'],
            /** @var array{set: bool, hint: string|null, source: 'settings'|'env'|null} The verify token for Meta's subscription handshake. */
            'verify_token' => $r['verify_token'],
            /** @var string Absolute callback URL to give Meta (from APP_URL). */
            'webhook_url' => $r['webhook_url'],
            /** @var bool App secret and verify token are both set. */
            'webhook_ready' => $r['webhook_ready'],
            /** @var bool Account id and send token are both set. */
            'send_ready' => $r['send_ready'],
            /** @var bool Always false until a real account has been connected and watched. */
            'verified' => $r['verified'],
            /**
             * The last "Test connection" (POST /settings/inbox-channels/{channel}/test), or null.
             * A passing test never marks the channel verified.
             *
             * @var array{ok: bool, checked_at: string, display_name: string|null, display_number: string|null, quality_rating: string|null, error_code: string|null, error_message: string|null}|null
             */
            'last_check' => $r['last_check'],
            /**
             * `not_connected`: no account id or send token. `reconnect`: Meta refused the token
             * (Graph error 190 — on the last test or on a send since the last good test) or the Page
             * dropped the app (`not_subscribed`). `connected` otherwise. `since` = when that was seen.
             *
             * @var array{state: 'connected'|'reconnect'|'not_connected', reason: string|null, since: string|null}
             */
            'connection' => $r['connection'],
            /**
             * The card's counts, from the inbox tables: open chats, waiting on a staff reply, unread
             * messages, the oldest wait, the last customer message (and the contact's name) and the
             * last webhook Meta delivered.
             *
             * @var array{open: int, waiting: int, unread: int, oldest_waiting_at: string|null, last_inbound_at: string|null, last_inbound_from: string|null, last_event_at: string|null}
             */
            'activity' => $r['activity'],
            /** @var string|null Messenger: the Page's name, saved by "Continue with Facebook". */
            'page_name' => $r['page_name'],
            /** @var string|null Messenger: when the Page was connected with Facebook (ISO 8601). */
            'connected_at' => $r['connected_at'],
            /** @var string|null Messenger: who connected it. */
            'connected_by' => $r['connected_by'],
            /** @var string|null Messenger: Toy Mart's Meta App ID. */
            'app_id' => $r['app_id'],
            /** @var 'settings'|'env'|null */
            'app_id_source' => $r['app_id_source'],
            /** @var string|null Messenger: the Facebook Login for Business configuration ID (null = the login asks for the permissions by name). */
            'login_config_id' => $r['login_config_id'],
            /** @var 'settings'|'env'|null */
            'login_config_id_source' => $r['login_config_id_source'],
            /** @var bool Messenger: App ID and App secret are set, so "Continue with Facebook" can start. */
            'login_ready' => $r['login_ready'],
            /** @var string|null Messenger: paste into the Meta app's "Valid OAuth Redirect URIs". */
            'oauth_redirect_uri' => $r['oauth_redirect_uri'],
        ];
    }
}

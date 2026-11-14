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
 * @property array{channel: string, driver: string, driver_source: string, account_id: ?string, account_id_source: ?string, send_token: array{set: bool, hint: ?string, source: ?string}, app_secret: array{set: bool, hint: ?string, source: ?string}, verify_token: array{set: bool, hint: ?string, source: ?string}, webhook_url: string, webhook_ready: bool, send_ready: bool, verified: bool} $resource
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
        ];
    }
}

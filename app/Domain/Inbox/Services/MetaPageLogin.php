<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Services;

use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Exceptions\MetaConnectionFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Graph API calls behind "Continue with Facebook" (Inbox › Channels › Facebook Page), on
 * Toy Mart's OWN Meta app — never a scraped login (rule 24a). Meta's manual login flow, so the
 * admin loads no Facebook script and its CSP stays as it is:
 *
 *  1. {@see self::dialogUrl()} — `https://www.facebook.com/{v}/dialog/oauth` with `config_id`
 *     (Facebook Login for Business) or, without one, `scope` = the Page messaging permissions.
 *  2. {@see self::exchangeCode()} — `GET /{v}/oauth/access_token?client_id&redirect_uri&client_secret&code`,
 *     then `grant_type=fb_exchange_token` for a long-lived (~60 day) user token.
 *  3. {@see self::pages()} — `GET /{v}/me/accounts?fields=id,name,access_token,tasks`: a Page
 *     token from a long-lived user token has no expiry date (it can still be invalidated).
 *  4. {@see self::subscribe()} — `POST /{v}/{page-id}/subscribed_apps` with `subscribed_fields`,
 *     using the Page token; {@see self::unsubscribe()} is the DELETE with the app token.
 *
 * Sources (read 2026-11-20): developers.facebook.com docs/facebook-login/facebook-login-for-business,
 * …/guides/advanced/manual-flow, …/guides/access-tokens/get-long-lived,
 * docs/graph-api/reference/page/subscribed_apps, docs/messenger-platform/webhooks. NOT confirmed:
 * whether `config_id` is honoured by the manual dialog exactly as by the JS SDK, and whether
 * customers without a role on the app reach the webhook before App Review — UNVERIFIED until a
 * real Page is connected. Every call here happens outside a database transaction (rule 4).
 * Tokens and the app secret are never logged or put in an exception message.
 */
final readonly class MetaPageLogin
{
    /** What the Inbox needs from a Page (docs/permissions: pages_messaging needs the other two). */
    public const array SCOPES = ['pages_show_list', 'pages_manage_metadata', 'pages_messaging'];

    /** Webhook fields the Messenger driver reads (MessengerDriver::parse). */
    public const array FIELDS = ['messages', 'messaging_postbacks', 'message_deliveries', 'message_reads'];

    public function __construct(private InboxChannelSettings $settings) {}

    /** True when an App ID and the app secret are in place — without them the button stays disabled. */
    public function configured(): bool
    {
        return $this->appId() !== null && $this->settings->isSet(InboxChannel::Messenger, 'app_secret');
    }

    public function appId(): ?string
    {
        return $this->settings->get(InboxChannel::Messenger, 'app_id');
    }

    /** Where Facebook sends the owner back: the Facebook Page channel screen. Paste it into "Valid OAuth Redirect URIs". */
    public static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/admin/integrations/messenger';
    }

    public function dialogUrl(string $state): string
    {
        $appId = $this->appId() ?? throw new MetaConnectionFailed('Add the Meta app first: its App ID and App secret.', 'not_configured');

        $query = ['client_id' => $appId, 'redirect_uri' => self::redirectUri(), 'state' => $state, 'response_type' => 'code'];
        $config = $this->settings->get(InboxChannel::Messenger, 'login_config_id');
        if ($config !== null) {
            $query['config_id'] = $config;
        } else {
            $query['scope'] = implode(',', self::SCOPES);
        }

        return 'https://www.facebook.com/'.config('inbox.graph_version').'/dialog/oauth?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** The code from the redirect → a long-lived user token. */
    public function exchangeCode(string $code): string
    {
        [$appId, $secret] = $this->credentials();

        $short = $this->token($this->call(fn (PendingRequest $http) => $http->get($this->url('oauth/access_token'), [
            'client_id' => $appId,
            'redirect_uri' => self::redirectUri(),
            'client_secret' => $secret,
            'code' => $code,
        ])), 'Facebook did not accept the sign-in. Try Continue with Facebook again.');

        return $this->token($this->call(fn (PendingRequest $http) => $http->get($this->url('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $secret,
            'fb_exchange_token' => $short,
        ])), 'Facebook did not give a lasting sign-in. Try again.');
    }

    /**
     * The Pages the person chose in the Facebook window, each with its Page token.
     *
     * @return list<array{id: string, name: string, token: string, can_message: bool}>
     */
    public function pages(string $userToken): array
    {
        $response = $this->call(fn (PendingRequest $http) => $http->withToken($userToken)->get($this->url('me/accounts'), ['fields' => 'id,name,access_token,tasks', 'limit' => 100]));
        $this->ensureOk($response, 'Facebook did not list your Pages.');

        $pages = [];
        foreach ((array) $response->json('data') as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || ! is_string($row['access_token'] ?? null)) {
                continue;
            }
            $tasks = is_array($row['tasks'] ?? null) ? $row['tasks'] : [];
            $pages[] = [
                'id' => $row['id'],
                'name' => is_string($row['name'] ?? null) ? mb_substr($row['name'], 0, 120) : $row['id'],
                'token' => $row['access_token'],
                // subscribed_apps needs MANAGE (or CREATE_CONTENT / MODERATE); replying needs MESSAGING.
                'can_message' => in_array('MESSAGING', $tasks, true) || in_array('MANAGE', $tasks, true),
            ];
        }

        return $pages;
    }

    public function subscribe(string $pageId, string $pageToken): void
    {
        $response = $this->call(fn (PendingRequest $http) => $http->withToken($pageToken)->asForm()->post($this->url(rawurlencode($pageId).'/subscribed_apps'), ['subscribed_fields' => implode(',', self::FIELDS)]));
        $this->ensureOk($response, 'Facebook did not subscribe the Page to messages.');
        if ($response->json('success') !== true) {
            throw new MetaConnectionFailed('Facebook did not subscribe the Page to messages.', 'not_subscribed');
        }
    }

    /** Best effort: true when Meta confirmed the Page no longer sends us its chats. */
    public function unsubscribe(string $pageId): bool
    {
        if (! $this->configured()) {
            return false;
        }
        [$appId, $secret] = $this->credentials();

        try {
            $response = $this->call(fn (PendingRequest $http) => $http->withToken($appId.'|'.$secret)->delete($this->url(rawurlencode($pageId).'/subscribed_apps')));
        } catch (MetaConnectionFailed) {
            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }

    /** @return array{0: string, 1: string} */
    private function credentials(): array
    {
        $appId = $this->appId();
        $secret = $this->settings->get(InboxChannel::Messenger, 'app_secret');
        if ($appId === null || $secret === null) {
            throw new MetaConnectionFailed('Add the Meta app first: its App ID and App secret.', 'not_configured');
        }

        return [$appId, $secret];
    }

    private function url(string $path): string
    {
        return rtrim((string) config('inbox.graph_base_url'), '/').'/'.config('inbox.graph_version').'/'.$path;
    }

    /** @param  callable(PendingRequest): Response  $send */
    private function call(callable $send): Response
    {
        try {
            return $send(Http::acceptJson()->timeout((int) config('inbox.timeout', 10)));
        } catch (ConnectionException) {
            // The exception text can carry the URL (and with it the code or secret): fixed text only.
            throw new MetaConnectionFailed('Facebook could not be reached. Try again in a minute.', 'unreachable', retryable: true);
        }
    }

    private function token(Response $response, string $message): string
    {
        $this->ensureOk($response, $message);
        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new MetaConnectionFailed($message, 'no_token');
        }

        return $token;
    }

    private function ensureOk(Response $response, string $message): void
    {
        if ($response->successful()) {
            return;
        }
        if ($response->serverError()) {
            throw new MetaConnectionFailed('Facebook could not be reached. Try again in a minute.', 'unreachable', retryable: true);
        }
        $code = $response->json('error.code');
        $reason = is_int($code) || (is_string($code) && ctype_digit($code)) ? 'meta_'.$code : 'http_'.$response->status();
        $detail = $response->json('error.error_user_msg') ?? $response->json('error.message');

        throw new MetaConnectionFailed(
            is_string($detail) && $detail !== '' ? $message.' Facebook said: '.mb_substr(self::scrub($detail), 0, 200) : $message,
            $reason,
        );
    }

    /** Meta echoes back parameters at times; never let a long token-shaped string through. */
    private static function scrub(string $text): string
    {
        return (string) preg_replace('/[A-Za-z0-9|_-]{40,}/', '••••', $text);
    }
}

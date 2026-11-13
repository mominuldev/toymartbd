<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Drivers\Pathao;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * HTTP + OAuth for Pathao's `/aladdin/api/v1` (docs/_research/agent3 §5). Shared by the
 * gateway and the location sync.
 *
 * Token lifecycle, as OBSERVED on the sandbox on 2026-11-13 (not as documented):
 *   - `POST /issue-token` with `grant_type=password` works with the published test account;
 *     `expires_in` came back 7,776,000 s (90 days), not the documented 432,000 (5 days) —
 *     so we trust the response, never a constant.
 *   - `grant_type=refresh_token` works, and it REVOKES the previous access token: the old
 *     token then gets 401 `verify-auth-token` / "user not found". So a refresh must replace
 *     the cached pair atomically, and a 401 first checks whether another worker already
 *     refreshed before refreshing again.
 *   - an invalid or revoked token gets HTTP 401 with `error.source = verify-auth-token`; a
 *     missing one got 400 in research. Both are treated as "re-authenticate once".
 *   - `POST /external/login` (client-credentials only, used by Pathao's own plugin) answered
 *     400 "The user credentials were incorrect" for the published sandbox client — not used.
 *
 * The token pair is cached encrypted (Crypt) with the provider's own expiry minus a margin,
 * and never logged. A transport failure (timeout, connection, 5xx) is thrown as
 * {@see PathaoException}; any 4xx answer is returned for the caller to read.
 */
final class PathaoClient
{
    private const string PREFIX = '/aladdin/api/v1';

    /** Seconds shaved off `expires_in` so a token is never used in its last minutes. */
    private const int EXPIRY_MARGIN = 300;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Cache $cache,
        private readonly PathaoCredentials $credentials,
    ) {}

    public function credentials(): PathaoCredentials
    {
        return $this->credentials;
    }

    public function get(string $path, string $operation): Response
    {
        return $this->send('GET', $path, null, $operation);
    }

    /** @param  array<string, mixed>  $body */
    public function post(string $path, array $body, string $operation): Response
    {
        return $this->send('POST', $path, $body, $operation);
    }

    /** Drop the cached token pair (tests, and an operator rotating credentials). */
    public function forgetToken(): void
    {
        $this->cache->forget($this->credentials->cacheKey());
    }

    /** @param  array<string, mixed>|null  $body */
    private function send(string $method, string $path, ?array $body, string $operation): Response
    {
        $token = $this->accessToken();
        $response = $this->call($method, $path, $body, $token, $operation);

        if (self::isAuthFailure($response)) {
            $token = $this->reauthenticate($token);
            $response = $this->call($method, $path, $body, $token, $operation);
            if (self::isAuthFailure($response)) {
                throw PathaoException::auth('token rejected after re-authentication');
            }
        }

        return $response;
    }

    /** @param  array<string, mixed>|null  $body */
    private function call(string $method, string $path, ?array $body, string $token, string $operation): Response
    {
        try {
            $request = $this->request()->withToken($token);
            $response = $method === 'GET'
                ? $request->get(self::PREFIX.$path)
                : $request->post(self::PREFIX.$path, $body ?? []);
        } catch (ConnectionException $e) {
            throw PathaoException::transport($operation, previous: $e);
        }

        if ($response->serverError()) {
            throw PathaoException::transport($operation, $response->status());
        }

        return $response;
    }

    private function accessToken(): string
    {
        $pair = $this->cachedPair();

        return $pair['access'] ?? $this->withLock(fn (): string => $this->cachedPair()['access'] ?? $this->issue(null));
    }

    /** A 401: refresh — unless another worker already replaced the token we used. */
    private function reauthenticate(string $rejected): string
    {
        return $this->withLock(function () use ($rejected): string {
            $pair = $this->cachedPair();
            if (isset($pair['access']) && ! hash_equals($pair['access'], $rejected)) {
                return $pair['access'];
            }

            return $this->issue($pair['refresh'] ?? null);
        });
    }

    /** Refresh grant when we hold a refresh token, else (or if refresh is refused) the password grant. */
    private function issue(?string $refreshToken): string
    {
        $c = $this->credentials;

        if ($refreshToken !== null) {
            $pair = $this->grant(['client_id' => $c->clientId, 'client_secret' => $c->clientSecret, 'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
            if ($pair !== null) {
                return $pair;
            }
        }

        $pair = $this->grant(['client_id' => $c->clientId, 'client_secret' => $c->clientSecret, 'grant_type' => 'password', 'username' => $c->username, 'password' => $c->password]);

        return $pair ?? throw PathaoException::auth('token issue refused');
    }

    /**
     * @param  array<string, string>  $body
     */
    private function grant(array $body): ?string
    {
        try {
            $response = $this->request()->post(self::PREFIX.'/issue-token', $body);
        } catch (ConnectionException $e) {
            throw PathaoException::transport('token issue', previous: $e);
        }

        if ($response->serverError()) {
            throw PathaoException::transport('token issue', $response->status());
        }

        $access = $response->json('access_token');
        $refresh = $response->json('refresh_token');
        $expires = $response->json('expires_in');
        if (! $response->successful() || ! is_string($access) || $access === '') {
            $this->forgetToken();

            return null;
        }

        $ttl = max(60, (is_numeric($expires) ? (int) $expires : 3600) - self::EXPIRY_MARGIN);
        $this->cache->put($this->credentials->cacheKey(), Crypt::encryptString((string) json_encode([
            'access' => $access,
            'refresh' => is_string($refresh) ? $refresh : null,
        ])), $ttl);

        return $access;
    }

    /** @return array{access?: string, refresh?: string|null} */
    private function cachedPair(): array
    {
        $stored = $this->cache->get($this->credentials->cacheKey());
        if (! is_string($stored)) {
            return [];
        }

        try {
            $pair = json_decode(Crypt::decryptString($stored), true);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($pair) || ! is_string($pair['access'] ?? null)) {
            return [];
        }

        return ['access' => $pair['access'], 'refresh' => is_string($pair['refresh'] ?? null) ? $pair['refresh'] : null];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            return $callback();
        }

        return $store->lock($this->credentials->cacheKey().':lock', 30)->block(15, $callback);
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->http->baseUrl($this->credentials->host())
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('couriers.pathao.timeout', 20))
            ->connectTimeout((int) config('couriers.pathao.connect_timeout', 5));
    }

    private static function isAuthFailure(Response $response): bool
    {
        if ($response->status() === 401) {
            return true;
        }

        return $response->status() === 400 && $response->json('error.source') === 'verify-auth-token';
    }
}

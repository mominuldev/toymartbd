<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateways\Bkash;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * bKash's `id_token` for one key set (docs/_research/bkash-api.md §2):
 *
 *   - Grant Token → `id_token` (valid `expires_in`, default 3600 s) + `refresh_token` (30 days).
 *   - bKash recommends refreshing at minute 50–55, and refreshing MORE THAN TWICE AN HOUR
 *     BLOCKS THE APP FOR AN HOUR. So the token is reused until 10 minutes before it expires;
 *     a refresh is attempted at most twice per rolling hour (counted here), and otherwise —
 *     or if a refresh fails — a fresh grant is made instead.
 *   - One process at a time renews (a cache lock), so concurrent checkouts never stampede.
 *
 * The cached record is ENCRYPTED with the app key (rule 20: a bearer token is a credential)
 * and keyed by {@see BkashCredentials::cacheId()}, so new keys never reuse an old token.
 * Request bodies (they carry the app secret) and transport messages are never logged.
 */
final readonly class BkashTokenStore
{
    private const string PREFIX = 'payments:bkash:token:';

    /** Reuse an id_token until this many seconds before bKash says it expires. */
    private const int EARLY_SECONDS = 600;

    /** bKash's documented limit: refresh at most twice an hour. */
    private const int MAX_REFRESHES_PER_HOUR = 2;

    public function __construct(private HttpFactory $http) {}

    /** A usable id_token, or null when bKash refused the keys or could not be reached. */
    public function token(BkashCredentials $credentials, bool $renew = false): ?string
    {
        $key = self::PREFIX.$credentials->cacheId();
        if (! $renew && ($cached = self::usable($this->read($key))) !== null) {
            return $cached;
        }

        try {
            return Cache::lock($key.':lock', 40)->block(35, function () use ($credentials, $key, $renew): ?string {
                $record = $this->read($key);
                // Another process renewed while this one waited.
                if (($cached = self::usable($record)) !== null && (! $renew || ($record['issued_at'] ?? 0) > now()->getTimestamp() - 30)) {
                    return $cached;
                }

                $fresh = 'unreachable';
                $refresh = is_array($record) && is_string($record['refresh_token'] ?? null) && ($record['refresh_expires_at'] ?? 0) > now()->getTimestamp()
                    ? $record['refresh_token']
                    : null;
                if ($refresh !== null && $this->mayRefresh($key)) {
                    $fresh = $this->call($credentials, 'refresh', ['refresh_token' => $refresh]);
                }
                if (! is_array($fresh)) {
                    $fresh = $this->call($credentials, 'grant');
                }

                return is_array($fresh) ? $this->store($key, $fresh) : null;
            });
        } catch (LockTimeoutException) {
            return null;
        }
    }

    /**
     * Integrations › Payments › Check connection: one Grant Token call with these keys (takes no
     * money, opens nothing). A granted token is cached and used.
     *
     * @return 'ok'|'credentials_rejected'|'unreachable'
     */
    public function check(BkashCredentials $credentials): string
    {
        $fresh = $this->call($credentials, 'grant');
        if (! is_array($fresh)) {
            return $fresh;
        }
        $this->store(self::PREFIX.$credentials->cacheId(), $fresh);

        return 'ok';
    }

    /** Drop the cached token (bKash refused it). The refresh token is kept. */
    public function forget(BkashCredentials $credentials): void
    {
        $key = self::PREFIX.$credentials->cacheId();
        $record = $this->read($key);
        if (is_array($record)) {
            $record['expires_at'] = 0;
            Cache::put($key, Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)), now()->addDays(29));
        }
    }

    /**
     * One Grant Token / Refresh Token call. Returns the record to cache, or null on any
     * refusal or unreadable answer (statusCode other than "0000", no id_token).
     *
     * @param  array<string, string>  $extra
     * @return array{id_token: string, refresh_token: string|null, expires_at: int, refresh_expires_at: int, issued_at: int}|'credentials_rejected'|'unreachable'
     */
    private function call(BkashCredentials $credentials, string $kind, array $extra = []): array|string
    {
        $path = self::path($kind);
        try {
            $response = $this->http->timeout((int) config('payments.bkash.timeout_seconds', 30))
                ->connectTimeout(5)
                ->acceptJson()
                ->asJson()
                ->withHeaders(['username' => $credentials->username, 'password' => $credentials->password])
                ->post($credentials->baseUrl.$path, ['app_key' => $credentials->appKey, 'app_secret' => $credentials->appSecret] + $extra);
        } catch (Throwable $e) {
            Log::warning('payment.bkash.transport_error', ['action' => $kind.'_token', 'exception' => $e::class]);

            return 'unreachable';
        }
        if ($response->serverError()) {
            return 'unreachable';
        }

        $body = self::decode($response);
        $token = is_array($body) && is_string($body['id_token'] ?? null) && $body['id_token'] !== '' ? $body['id_token'] : null;
        if ($token === null || ($body['statusCode'] ?? '0000') !== '0000') {
            Log::warning('payment.bkash.token_refused', [
                'action' => $kind,
                'http_status' => $response->status(),
                // bKash's code only (e.g. "9999") — never its message, which may echo input.
                'status_code' => is_array($body) && is_scalar($body['statusCode'] ?? null) ? (string) $body['statusCode'] : null,
                'path' => $path,
            ]);

            // An answer bKash could not read is not proof the keys are wrong.
            return $body === null ? 'unreachable' : 'credentials_rejected';
        }

        $expiresIn = is_numeric($body['expires_in'] ?? null) ? (int) $body['expires_in'] : 3600;
        $refresh = is_string($body['refresh_token'] ?? null) && $body['refresh_token'] !== '' ? $body['refresh_token'] : null;

        return [
            'id_token' => $token,
            'refresh_token' => $refresh,
            'expires_at' => now()->getTimestamp() + max(60, $expiresIn - self::EARLY_SECONDS),
            // Documented as 30 days; one day of margin.
            'refresh_expires_at' => now()->getTimestamp() + 29 * 86400,
            'issued_at' => now()->getTimestamp(),
        ];
    }

    /** @param  array{id_token: string, refresh_token: string|null, expires_at: int, refresh_expires_at: int, issued_at: int}  $record */
    private function store(string $key, array $record): string
    {
        Cache::put($key, Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)), now()->addDays(29));

        return $record['id_token'];
    }

    private static function path(string $kind): string
    {
        return $kind === 'refresh' ? '/auth/refresh-token' : '/auth/grant-token';
    }

    /** Counts refreshes in a rolling hour; false once the documented limit is reached. */
    private function mayRefresh(string $key): bool
    {
        $counter = $key.':refreshes';
        Cache::add($counter, 0, 3600);
        if ((int) Cache::get($counter, 0) >= self::MAX_REFRESHES_PER_HOUR) {
            return false;
        }
        Cache::increment($counter);

        return true;
    }

    /** @return array<string, mixed>|null */
    private function read(string $key): ?array
    {
        $raw = Cache::get($key);
        if (! is_string($raw)) {
            return null;
        }
        try {
            $decoded = json_decode(Crypt::decryptString($raw), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param  array<string, mixed>|null  $record */
    private static function usable(?array $record): ?string
    {
        if ($record === null || ! is_string($record['id_token'] ?? null) || ($record['expires_at'] ?? 0) <= now()->getTimestamp()) {
            return null;
        }

        return $record['id_token'];
    }

    /** @return array<string, mixed>|null */
    private static function decode(Response $response): ?array
    {
        try {
            $decoded = json_decode($response->body(), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}

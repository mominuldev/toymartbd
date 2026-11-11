<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problems\ProblemDetails;
use App\Http\Problems\ProblemType;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `Idempotency-Key` guard (P3-API-03, CLAUDE.md rule 17, SYSTEM-ARCHITECTURE.md §5).
 *
 * Attach with the `idempotent` alias to the routes that must not run twice
 * (POST /checkout, shipment booking, payment capture). Statuses follow
 * draft-ietf-httpapi-idempotency-key-header:
 *
 *   - missing header            → 400 `idempotency-key-missing`
 *   - malformed key             → 400 `idempotency-key-invalid` (8–255 printable ASCII, no spaces; a UUID fits)
 *   - same key, different body  → 422 `idempotency-key-reused`
 *   - same key, first in flight → 409 `request-in-progress` + `Retry-After: 1` (no waiting:
 *                                 a PHP worker blocked on a lock is a worker not serving anyone)
 *   - same key, completed       → the stored response, byte-identical, plus `Idempotent-Replayed: true`
 *
 * A key is scoped by audience (storefront/admin/webhooks) + principal (authenticated user, else
 * client IP) + method + path, so two users can never see each other's replay. The fingerprint is
 * method + path + sorted query + canonical body (JSON objects key-sorted recursively).
 *
 * Only 2xx responses are stored. A 4xx means nothing changed (Actions are transactional), so a
 * retry with the same key — e.g. after fixing a validation error — re-executes rather than
 * replaying the failure; a 5xx is never stored. Streamed/file responses are never stored.
 * `Set-Cookie` is stripped from what is stored, so a replay never rewinds a session.
 *
 * Records live in the cache store (Redis in production) for config('idempotency.ttl_seconds');
 * the in-flight guard is an atomic `Cache::lock`. Bodies are stored as-is, so a marked route
 * MUST NOT return a credential, token or secret in its response.
 */
final class EnsureIdempotency
{
    public const string HEADER = 'Idempotency-Key';

    public const string REPLAYED_HEADER = 'Idempotent-Replayed';

    private const string KEY_PATTERN = '/^[\x21-\x7E]{8,255}$/';

    /** Headers never persisted with a stored response. */
    private const array UNSTORED_HEADERS = ['set-cookie', 'date'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $key = $request->headers->get(self::HEADER);

        if ($key === null || $key === '') {
            return ProblemDetails::make(ProblemType::IdempotencyKeyMissing)->toResponse($request);
        }

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            return ProblemDetails::make(ProblemType::IdempotencyKeyInvalid)->toResponse($request);
        }

        $storageKey = self::storageKey($request, $key);
        $fingerprint = self::fingerprint($request);
        $cache = self::cache();

        if (($stored = $this->stored($cache, $storageKey)) !== null) {
            return $this->replayOrReject($request, $stored, $fingerprint);
        }

        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('The idempotency cache store must support atomic locks.');
        }

        $lock = $store->lock(self::lockName($storageKey), self::lockSeconds());

        if (! $lock->get()) {
            return $this->inProgress($request);
        }

        try {
            // Double-checked: the first request may have completed between the read and the lock.
            if (($stored = $this->stored($cache, $storageKey)) !== null) {
                return $this->replayOrReject($request, $stored, $fingerprint);
            }

            $response = $next($request);

            if ($this->shouldStore($response)) {
                $cache->put($storageKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'headers' => $this->storableHeaders($response),
                    'body' => (string) $response->getContent(),
                ], self::ttlSeconds());
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * Cache key for a request + Idempotency-Key. Public so tests can hold the in-flight lock.
     */
    public static function storageKey(Request $request, string $key): string
    {
        $user = $request->user();
        $principal = $user !== null
            ? 'user:'.$user::class.':'.$user->getAuthIdentifier()
            : 'ip:'.$request->ip();

        $path = '/'.ltrim($request->path(), '/');
        $audience = preg_match('#^/api/v\d+/([^/]+)#', $path, $m) === 1 ? $m[1] : 'other';

        return 'idempotency:'.hash('sha256', implode("\n", [
            $audience, $principal, $request->getMethod(), $path, $key,
        ]));
    }

    public static function lockName(string $storageKey): string
    {
        return $storageKey.':lock';
    }

    public static function fingerprint(Request $request): string
    {
        $query = $request->query->all();
        self::ksortRecursive($query);

        $body = $request->isJson()
            ? json_decode((string) $request->getContent(), true)
            : $request->request->all();

        if (is_array($body)) {
            self::ksortRecursive($body);
        }

        return hash('sha256', (string) json_encode(
            [$request->getMethod(), '/'.ltrim($request->path(), '/'), $query, $body],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /**
     * @param  array{fingerprint: string, status: int, headers: array<string, list<string|null>>, body: string}  $stored
     */
    private function replayOrReject(Request $request, array $stored, string $fingerprint): Response
    {
        if (! hash_equals($stored['fingerprint'], $fingerprint)) {
            return ProblemDetails::make(ProblemType::IdempotencyKeyReused)->toResponse($request);
        }

        $response = new Response($stored['body'], $stored['status'], $stored['headers']);
        $response->headers->set(self::REPLAYED_HEADER, 'true');

        return $response;
    }

    private function inProgress(Request $request): Response
    {
        $problem = ProblemDetails::make(ProblemType::RequestInProgress);

        return (new ProblemDetails($problem->type, $problem->status, $problem->detail, headers: ['Retry-After' => '1']))
            ->toResponse($request);
    }

    /**
     * Reads the cache, so its result can change between two calls (the double-check above).
     *
     * @phpstan-impure
     *
     * @return array{fingerprint: string, status: int, headers: array<string, list<string|null>>, body: string}|null
     */
    private function stored(Repository $cache, string $storageKey): ?array
    {
        $value = $cache->get($storageKey);

        if (! is_array($value) || ! isset($value['fingerprint'], $value['status'], $value['headers'], $value['body'])) {
            return null;
        }

        /** @var array{fingerprint: string, status: int, headers: array<string, list<string|null>>, body: string} $value */
        return $value;
    }

    private function shouldStore(Response $response): bool
    {
        return $response->isSuccessful()
            && ! $response instanceof StreamedResponse
            && ! $response instanceof BinaryFileResponse;
    }

    /** @return array<string, list<string|null>> */
    private function storableHeaders(Response $response): array
    {
        /** @var array<string, list<string|null>> $headers */
        $headers = $response->headers->allPreserveCaseWithoutCookies();

        return array_filter(
            $headers,
            static fn (string $name): bool => ! in_array(strtolower($name), self::UNSTORED_HEADERS, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @param  array<array-key, mixed>  $value */
    private static function ksortRecursive(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as &$item) {
            if (is_array($item)) {
                self::ksortRecursive($item);
            }
        }
    }

    private static function cache(): Repository
    {
        $store = config('idempotency.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    private static function ttlSeconds(): int
    {
        $ttl = config('idempotency.ttl_seconds', 86400);

        return is_numeric($ttl) ? (int) $ttl : 86400;
    }

    private static function lockSeconds(): int
    {
        $seconds = config('idempotency.lock_seconds', 60);

        return is_numeric($seconds) ? (int) $seconds : 60;
    }
}

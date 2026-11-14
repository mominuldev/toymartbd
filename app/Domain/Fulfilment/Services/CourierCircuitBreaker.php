<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Services;

use App\Models\SystemHeartbeat;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Per-provider circuit breaker for courier calls. `failure_threshold` consecutive
 * TRANSPORT failures (a thrown driver call — timeout, connection error, 5xx) open the
 * circuit for `open_minutes`; while open, booking jobs release themselves back to the
 * queue instead of calling a provider that is down. Any provider answer — accepted or a
 * definite refusal — closes it and resets the count. Cache-backed (Redis in production), so
 * every worker shares one view of the provider.
 */
final readonly class CourierCircuitBreaker
{
    public function __construct(private Cache $cache) {}

    public function isOpen(string $code): bool
    {
        return $this->retryAfterSeconds($code) > 0;
    }

    /** Seconds until the circuit closes (0 when closed). */
    public function retryAfterSeconds(string $code): int
    {
        $until = $this->cache->get(self::openKey($code));

        return is_int($until) ? max(0, $until - time()) : 0;
    }

    public function recordFailure(string $code): void
    {
        $failures = (int) $this->cache->get(self::failuresKey($code), 0) + 1;

        if ($failures >= self::threshold()) {
            $seconds = self::openMinutes() * 60;
            $this->cache->put(self::openKey($code), time() + $seconds, $seconds);
            $this->cache->forget(self::failuresKey($code));

            return;
        }

        $this->cache->put(self::failuresKey($code), $failures, 3600);
    }

    public function recordSuccess(string $code): void
    {
        $this->cache->forget(self::failuresKey($code));
        $this->cache->forget(self::openKey($code));
        // "Last successful courier sync" for the health endpoint and the courier_poll_silence
        // alert (P10 monitoring). Never throws.
        SystemHeartbeat::beat(SystemHeartbeat::COURIER_PREFIX.$code);
    }

    public function failures(string $code): int
    {
        return (int) $this->cache->get(self::failuresKey($code), 0);
    }

    private static function threshold(): int
    {
        return max(1, (int) config('couriers.circuit_breaker.failure_threshold', 5));
    }

    private static function openMinutes(): int
    {
        return max(1, (int) config('couriers.circuit_breaker.open_minutes', 5));
    }

    private static function failuresKey(string $code): string
    {
        return "courier:breaker:{$code}:failures";
    }

    private static function openKey(string $code): string
    {
        return "courier:breaker:{$code}:open_until";
    }
}

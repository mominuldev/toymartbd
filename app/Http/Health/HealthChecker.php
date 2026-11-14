<?php

declare(strict_types=1);

namespace App\Http\Health;

use App\Domain\Monitoring\Alerts\InFlightShipments;
use App\Domain\Monitoring\Services\MonitoringSettings;
use App\Domain\Monitoring\Services\QueueMonitor;
use App\Domain\Search\Services\IndexLagCheck;
use App\Models\SystemHeartbeat;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Probes the API's runtime dependencies for GET /api/v1/health (SYSTEM-ARCHITECTURE §11:
 * database, Redis, queue depth, Meilisearch, last successful courier sync — plus the
 * scheduler heartbeat, Horizon, the search-index lag result and the last backup's age).
 *
 * The detail never carries a DSN, host, driver version or exception message: failures are
 * logged here and the response says only `fail`. Heartbeat ages come from `system_heartbeats`.
 */
final readonly class HealthChecker
{
    private const float MEILISEARCH_TIMEOUT_SECONDS = 2.0;

    public const string CACHE_KEY = 'health:report';

    public function __construct(private QueueMonitor $queues) {}

    /**
     * The report, reused for `monitoring.health.cache_seconds` so a burst of uptime checks
     * cannot load the database. A cache that cannot be read or written is itself what the
     * report is about — it is then computed fresh every time.
     */
    public function cached(): HealthReport
    {
        $ttl = (int) config('monitoring.health.cache_seconds', 10);

        try {
            $hit = $ttl > 0 ? HealthReport::fromCache(Cache::get(self::CACHE_KEY)) : null;
            if ($hit !== null) {
                return $hit;
            }
        } catch (Throwable) {
            // Fall through.
        }

        $report = $this->run();

        try {
            if ($ttl > 0) {
                Cache::put(self::CACHE_KEY, $report->toCache(), $ttl);
            }
        } catch (Throwable) {
            // Not cached.
        }

        return $report;
    }

    public function run(): HealthReport
    {
        $settings = new MonitoringSettings;

        return new HealthReport([
            'database' => $this->probe('database', function (): void {
                DB::connection()->select('select 1');
            }),
            // Redis in staging/production (CACHE_STORE=redis); the store the app actually uses.
            'cache' => $this->probe('cache', function (): void {
                $key = 'health:probe:'.Str::random(8);
                $token = Str::random(16);
                Cache::put($key, $token, 10);
                $read = Cache::get($key);
                Cache::forget($key);

                if ($read !== $token) {
                    throw new RuntimeException('Cache read-back mismatch.');
                }
            }),
            'redis' => $this->redis(),
            'queue' => $this->queue($settings),
            'horizon' => $this->horizon(),
            'meilisearch' => $this->meilisearch(),
            'search_index' => $this->searchIndex(),
            'scheduler' => $this->heartbeat(SystemHeartbeat::SCHEDULER, max(1, (int) config('monitoring.health.scheduler_stale_minutes', 5)) * 60),
            'courier_sync' => $this->courierSync($settings),
            'backup' => $this->heartbeat(SystemHeartbeat::BACKUP, max(1, $settings->int('alerts.backup_stale.hours', 26)) * 3600),
        ], new DateTimeImmutable);
    }

    /** @return array{status: HealthStatus, latency_ms: float|null} */
    private function redis(): array
    {
        $uses = config('queue.default') === 'redis'
            || config('cache.stores.'.config('cache.default').'.driver') === 'redis'
            || config('session.driver') === 'redis';

        if (! $uses) {
            return ['status' => HealthStatus::Skipped, 'latency_ms' => null];
        }

        return $this->probe('redis', function (): void {
            Redis::connection()->command('ping');
        });
    }

    /** @return array{status: HealthStatus, latency_ms: float|null, depths?: array<string, int>, threshold?: int} */
    private function queue(MonitoringSettings $settings): array
    {
        $depths = null;
        $result = $this->probe('queue', function () use (&$depths): void {
            $depths = $this->queues->depths();
        });

        if (! is_array($depths)) {
            return $result;
        }

        $threshold = max(1, $settings->int('alerts.queue_depth.threshold', 500));
        $over = array_filter($depths, static fn (int $n): bool => $n > $threshold);

        return [...$result, 'status' => $over === [] ? $result['status'] : HealthStatus::Fail, 'depths' => $depths, 'threshold' => $threshold];
    }

    /** @return array{status: HealthStatus, latency_ms: null} */
    private function horizon(): array
    {
        $running = $this->queues->horizonRunning();

        return ['status' => match ($running) {
            null => HealthStatus::Skipped,
            true => HealthStatus::Ok,
            false => HealthStatus::Fail,
        }, 'latency_ms' => null];
    }

    /** @return array{status: HealthStatus, latency_ms: float|null} */
    private function meilisearch(): array
    {
        if (config('scout.driver') !== 'meilisearch') {
            return ['status' => HealthStatus::Skipped, 'latency_ms' => null];
        }

        return $this->probe('meilisearch', function (): void {
            $host = config('scout.meilisearch.host');

            if (! is_string($host) || $host === '') {
                throw new RuntimeException('Meilisearch host is not configured.');
            }

            $response = Http::connectTimeout(self::MEILISEARCH_TIMEOUT_SECONDS)
                ->timeout(self::MEILISEARCH_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get(rtrim($host, '/').'/health');

            if (! $response->successful() || $response->json('status') !== 'available') {
                throw new RuntimeException('Meilisearch is not available (HTTP '.$response->status().').');
            }
        });
    }

    /**
     * The last `toymart:search:lag-check` result (cached by IndexLagCheck); never run = skipped.
     *
     * @return array{status: HealthStatus, latency_ms: null}
     */
    private function searchIndex(): array
    {
        if (config('scout.driver') !== 'meilisearch') {
            return ['status' => HealthStatus::Skipped, 'latency_ms' => null];
        }

        try {
            $healthy = IndexLagCheck::lastHealthy();
        } catch (Throwable) {
            $healthy = false;
        }

        return ['status' => match ($healthy) {
            null => HealthStatus::Skipped,
            true => HealthStatus::Ok,
            false => HealthStatus::Fail,
        }, 'latency_ms' => null];
    }

    /**
     * The last successful courier API answer (any provider). Stale only matters while a
     * courier holds shipments; otherwise there is nothing to sync and the check is `ok`.
     *
     * @return array{status: HealthStatus, latency_ms: null, last_success_at: string|null}
     */
    private function courierSync(MonitoringSettings $settings): array
    {
        try {
            $last = SystemHeartbeat::latestSuccessWithPrefix(SystemHeartbeat::COURIER_PREFIX);
            $cutoff = now()->subMinutes(max(1, $settings->int('alerts.courier_poll_silence.minutes', 90)))->toImmutable();
            $stale = $last === null || $last->lessThan($cutoff);
            $status = ! $stale || InFlightShipments::providersWithShipmentsOlderThan($cutoff) === [] ? HealthStatus::Ok : HealthStatus::Fail;
        } catch (Throwable $e) {
            Log::warning('Health check failed.', ['check' => 'courier_sync', 'exception' => $e::class]);

            return ['status' => HealthStatus::Fail, 'latency_ms' => null, 'last_success_at' => null];
        }

        return ['status' => $status, 'latency_ms' => null, 'last_success_at' => self::iso($last)];
    }

    /**
     * A duty that must beat at least every $maxAgeSeconds. Never beaten = `fail` where
     * heartbeats are expected (staging/production), `skipped` elsewhere (local, CI).
     *
     * @return array{status: HealthStatus, latency_ms: null, last_success_at: string|null}
     */
    private function heartbeat(string $key, int $maxAgeSeconds): array
    {
        try {
            $last = SystemHeartbeat::query()->find($key)?->last_success_at;
        } catch (Throwable $e) {
            Log::warning('Health check failed.', ['check' => $key, 'exception' => $e::class]);

            return ['status' => HealthStatus::Fail, 'latency_ms' => null, 'last_success_at' => null];
        }

        $status = match (true) {
            $last === null => (bool) config('monitoring.health.expect_heartbeats', false) ? HealthStatus::Fail : HealthStatus::Skipped,
            $last->lessThan(now()->subSeconds($maxAgeSeconds)) => HealthStatus::Fail,
            default => HealthStatus::Ok,
        };

        return ['status' => $status, 'latency_ms' => null, 'last_success_at' => self::iso($last)];
    }

    private static function iso(?Carbon $at): ?string
    {
        return $at?->utc()->format(DateTimeInterface::ATOM);
    }

    /**
     * @param  Closure(): void  $check
     * @return array{status: HealthStatus, latency_ms: float|null}
     */
    private function probe(string $name, Closure $check): array
    {
        $started = hrtime(true);

        try {
            $check();
            $status = HealthStatus::Ok;
        } catch (Throwable $e) {
            // Logged, not reported: an outage would otherwise flood the error tracker on every poll.
            Log::warning('Health check failed.', ['check' => $name, 'exception' => $e::class, 'message' => $e->getMessage()]);
            $status = HealthStatus::Fail;
        }

        return ['status' => $status, 'latency_ms' => round((hrtime(true) - $started) / 1e6, 1)];
    }
}

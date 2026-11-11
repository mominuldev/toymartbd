<?php

declare(strict_types=1);

namespace App\Http\Health;

use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Probes the API's runtime dependencies for GET /api/v1/health
 * (docs/SYSTEM-ARCHITECTURE.md §11).
 *
 * Reports only up/down, latency and queue depth: never a DSN, host, driver
 * version or exception message, because the endpoint is public.
 */
final class HealthChecker
{
    private const float MEILISEARCH_TIMEOUT_SECONDS = 2.0;

    public function run(): HealthReport
    {
        return new HealthReport([
            'database' => $this->probe('database', function (): void {
                DB::connection()->select('select 1');
            }),
            // Redis in staging/production (CACHE_STORE=redis), so this doubles as the Redis check.
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
            'queue' => $this->queue(),
            'meilisearch' => $this->meilisearch(),
        ], new DateTimeImmutable);
    }

    /** @return array{status: HealthStatus, latency_ms: float|null, size?: int} */
    private function queue(): array
    {
        $size = null;
        $result = $this->probe('queue', function () use (&$size): void {
            // LLEN on Redis, a COUNT on the database driver, 0 on sync — cheap on all of them.
            $size = Queue::size();
        });

        return is_int($size) ? [...$result, 'size' => $size] : $result;
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

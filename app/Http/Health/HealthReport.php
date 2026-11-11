<?php

declare(strict_types=1);

namespace App\Http\Health;

use DateTimeImmutable;

/**
 * The result of one health run. `status` is `ok` only when no check failed.
 *
 * Only the checks the API cannot serve without (database, cache) make the endpoint
 * return 503. A search outage shows as `degraded` with 200, so a load balancer
 * polling this endpoint does not pull the whole API — checkout included — out of
 * rotation because Meilisearch is down.
 */
final readonly class HealthReport
{
    private const CRITICAL = ['database', 'cache'];

    /**
     * @param  array<string, array{status: HealthStatus, latency_ms: float|null, size?: int}>  $checks
     */
    public function __construct(
        public array $checks,
        public DateTimeImmutable $checkedAt,
    ) {}

    public function healthy(): bool
    {
        foreach ($this->checks as $check) {
            if ($check['status'] === HealthStatus::Fail) {
                return false;
            }
        }

        return true;
    }

    /** True when a check the API cannot run without has failed. */
    public function critical(): bool
    {
        foreach (self::CRITICAL as $name) {
            if (($this->checks[$name]['status'] ?? null) === HealthStatus::Fail) {
                return true;
            }
        }

        return false;
    }
}

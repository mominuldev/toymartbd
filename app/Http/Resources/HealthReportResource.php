<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Health\HealthReport;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * GET /api/v1/health. The public body is `status` + `checked_at` only — nothing about the
 * infrastructure. With a valid `X-Health-Token` (monitoring.health.detail_token) it adds
 * `checks`: per dependency `status` (ok|fail|skipped) and, where relevant, `latency_ms`,
 * queue `depths`/`threshold`, or a heartbeat's `last_success_at` — never a host, DSN or error.
 *
 * @property HealthReport $resource
 */
final class HealthReportResource extends ApiResource
{
    public function __construct(HealthReport $resource, private readonly bool $detailed = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** `ok`, `degraded` (200: something non-critical failed) or `down` (503). */
            'status' => $this->resource->status(),
            'checked_at' => $this->resource->checkedAt->format(DateTimeInterface::ATOM),
            /** Only with a valid `X-Health-Token`. */
            'checks' => $this->when($this->detailed, fn (): array => array_map(
                static fn (array $check): array => [...$check, 'status' => $check['status']->value],
                $this->resource->checks,
            )),
        ];
    }
}

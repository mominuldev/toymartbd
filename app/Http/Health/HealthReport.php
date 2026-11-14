<?php

declare(strict_types=1);

namespace App\Http\Health;

use DateTimeImmutable;

/**
 * The result of one health run (SYSTEM-ARCHITECTURE §11).
 *
 *  - `down`     — a dependency the API cannot serve without failed (database, cache, Redis):
 *                 HTTP 503, so a load balancer takes the node out of rotation.
 *  - `degraded` — something else failed (search, queue backlog, Horizon, a stale scheduler,
 *                 courier sync or backup): HTTP 200 — checkout still works, a human must look.
 *  - `ok`       — nothing failed.
 */
final readonly class HealthReport
{
    /** Checks the API cannot serve a request without. */
    public const array CRITICAL = ['database', 'cache', 'redis'];

    /**
     * @param  array<string, array<string, mixed>&array{status: HealthStatus}>  $checks
     */
    public function __construct(
        public array $checks,
        public DateTimeImmutable $checkedAt,
    ) {}

    /**
     * Plain arrays only: the cache refuses to unserialize objects (`cache.serializable_classes`).
     *
     * @return array{checked_at: string, checks: array<string, array<string, mixed>>}
     */
    public function toCache(): array
    {
        return [
            'checked_at' => $this->checkedAt->format(DATE_ATOM),
            'checks' => array_map(static fn (array $c): array => [...$c, 'status' => $c['status']->value], $this->checks),
        ];
    }

    public static function fromCache(mixed $cached): ?self
    {
        if (! is_array($cached) || ! is_string($cached['checked_at'] ?? null) || ! is_array($cached['checks'] ?? null)) {
            return null;
        }

        $checks = [];
        foreach ($cached['checks'] as $name => $check) {
            $status = is_array($check) && is_string($check['status'] ?? null) ? HealthStatus::tryFrom($check['status']) : null;
            if ($status === null || ! is_string($name)) {
                return null;
            }
            $checks[$name] = [...$check, 'status' => $status];
        }
        $at = DateTimeImmutable::createFromFormat(DATE_ATOM, $cached['checked_at']);

        return $at === false ? null : new self($checks, $at);
    }

    public function status(): string
    {
        if ($this->critical()) {
            return 'down';
        }

        return $this->healthy() ? 'ok' : 'degraded';
    }

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

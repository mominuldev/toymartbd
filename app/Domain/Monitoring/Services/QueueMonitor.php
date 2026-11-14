<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Services;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\App;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Queue depth per queue and whether Horizon is running — shared by the health endpoint and the
 * queue_depth / horizon_down alerts, so both report the same numbers. The queue names come from
 * config/horizon.php (one supervisor per queue, SYSTEM-ARCHITECTURE §7), never a second list.
 */
final readonly class QueueMonitor
{
    public function __construct(private QueueFactory $queues) {}

    /** @return list<string> */
    public static function queueNames(): array
    {
        $names = [];
        foreach ((array) config('horizon.defaults', []) as $supervisor) {
            foreach ((array) (is_array($supervisor) ? ($supervisor['queue'] ?? []) : []) as $queue) {
                if (is_string($queue)) {
                    $names[] = $queue;
                }
            }
        }

        return array_values(array_unique($names === [] ? ['default'] : $names));
    }

    /**
     * Pending jobs per queue on the default connection (LLEN on Redis, a COUNT on the database
     * driver, 0 on sync). Throws when the queue backend is unreachable.
     *
     * @return array<string, int>
     */
    public function depths(): array
    {
        $connection = $this->queues->connection();
        $depths = [];
        foreach (self::queueNames() as $queue) {
            $depths[$queue] = $connection->size($queue);
        }

        return $depths;
    }

    /** Horizon only supervises Redis queues; anywhere else it is not applicable. */
    public static function horizonApplies(): bool
    {
        return config('queue.default') === 'redis' && interface_exists(MasterSupervisorRepository::class);
    }

    /** True when at least one Horizon master supervisor is alive; null when not applicable or unknown. */
    public function horizonRunning(): ?bool
    {
        if (! self::horizonApplies()) {
            return null;
        }

        try {
            return App::make(MasterSupervisorRepository::class)->all() !== [];
        } catch (Throwable) {
            return false;
        }
    }
}

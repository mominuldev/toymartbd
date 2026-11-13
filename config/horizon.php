<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Horizon — queue supervisors (SYSTEM-ARCHITECTURE.md §7)
|--------------------------------------------------------------------------
|
| Only the worker layout lives here; every other Horizon option keeps the package
| default (HorizonServiceProvider merges this file over vendor/laravel/horizon/config,
| top-level keys only).
|
| Without this file Horizon's default supervisor works the `default` queue only, and
| has no `staging` environment at all — so on staging nothing ran, and in production
| every job the app queues on critical / notify / index / reports (order notifications,
| media renditions, search sync, ledger write-offs) would have waited forever. Found by
| the staging smoke rehearsal (docs/ops/STAGING.md).
|
| One supervisor per queue, so a slow courier API never starves order confirmation.
| Process counts and retry counts are §7's table; a job's own $tries / $backoff wins.
|
*/

$supervisor = static fn (string $queue, int $processes, int $tries, int $timeout = 60): array => [
    'connection' => 'redis',
    'queue' => [$queue],
    'balance' => 'simple',
    'minProcesses' => 1,
    'maxProcesses' => $processes,
    'maxTime' => 3600,
    'maxJobs' => 1000,
    'memory' => 256,
    'tries' => $tries,
    'timeout' => $timeout,
    'nice' => 0,
];

$workers = [
    'supervisor-critical' => $supervisor('critical', 3, 3),
    'supervisor-courier' => $supervisor('courier', 2, 5, 120),
    'supervisor-notify' => $supervisor('notify', 2, 3),
    'supervisor-tracking' => $supervisor('tracking', 2, 3),
    'supervisor-index' => $supervisor('index', 1, 3, 300),
    'supervisor-reports' => $supervisor('reports', 1, 2, 600),
    // Queued listeners and jobs that name no queue.
    'supervisor-default' => $supervisor('default', 1, 3),
];

return [
    'defaults' => $workers,

    'environments' => [
        'production' => $workers,
        'staging' => $workers,
        'local' => $workers,
    ],
];

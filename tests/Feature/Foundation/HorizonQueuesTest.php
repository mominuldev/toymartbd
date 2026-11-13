<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * config/horizon.php (SYSTEM-ARCHITECTURE §7). Without it Horizon worked only the `default`
 * queue and had no `staging` environment, so order notifications (notify), media and
 * search sync (index), ledger write-offs (reports) and critical jobs never ran under
 * Horizon. Found by the staging smoke rehearsal (docs/ops/STAGING.md).
 */

/** @return list<string> every queue a job, listener or config default names in app/ and config/ */
function hqQueuesNamedInCode(): array
{
    $queues = ['default'];
    $files = Finder::create()->files()->name('*.php')->in([base_path('app'), base_path('config')]);
    foreach ($files as $file) {
        $code = $file->getContents();
        preg_match_all("/(?:\\\$queue\\s*=\\s*|onQueue\\(|'queue'\\s*=>\\s*env\\('(?:MEDIA|NOTIFICATIONS)_QUEUE',\\s*)'([a-z_-]+)'/", $code, $m);
        array_push($queues, ...$m[1]);
    }

    return array_values(array_unique($queues));
}

/** @return list<string> */
function hqSupervised(string $environment): array
{
    $supervisors = config("horizon.environments.{$environment}");
    expect($supervisors)->toBeArray()->not->toBeEmpty();

    $queues = [];
    foreach ($supervisors as $supervisor) {
        array_push($queues, ...(array) $supervisor['queue']);
    }

    return $queues;
}

it('has a Horizon supervisor for every queue the app dispatches to, in production, staging and local', function (string $environment): void {
    $supervised = hqSupervised($environment);

    expect(hqQueuesNamedInCode())->each(fn ($queue) => $queue->toBeIn($supervised))
        ->and($supervised)->toContain('critical', 'courier', 'notify', 'tracking', 'index', 'reports', 'default');
})->with(['production', 'staging', 'local']);

it('runs every supervisor on the redis connection', function (): void {
    foreach (config('horizon.environments.staging') as $name => $supervisor) {
        expect($supervisor['connection'])->toBe('redis', $name);
    }
});

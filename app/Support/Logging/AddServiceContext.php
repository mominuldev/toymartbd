<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Stamps every JSON log line with `extra.service` = { app, env, release } (P10, log
 * aggregation): once lines from the API, the workers and the scheduler land in one log store,
 * this is what filters "production, this deploy". `release` is the git SHA the deploy sets as
 * SENTRY_RELEASE, so a log line and a Sentry event of the same release join up. The request id
 * is added separately by Laravel's Context (extra.request_id).
 */
final readonly class AddServiceContext implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $release = config('sentry.release');

        return $record->with(extra: [
            ...$record->extra,
            'service' => [
                'app' => 'toymartbd-api',
                'env' => (string) config('app.env'),
                'release' => is_string($release) && $release !== '' ? $release : null,
            ],
        ]);
    }
}

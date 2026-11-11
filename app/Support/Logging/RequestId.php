<?php

declare(strict_types=1);

namespace App\Support\Logging;

/**
 * Where the correlation id lives (P3-CI-04). Set by App\Http\Middleware\AssignRequestId;
 * read by the Sentry scrubber, the problem renderer and anything that logs.
 */
final class RequestId
{
    public const string HEADER = 'X-Request-Id';

    /** Key in Laravel's Context — hence `extra.request_id` on every log line. */
    public const string CONTEXT_KEY = 'request_id';
}

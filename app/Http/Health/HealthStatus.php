<?php

declare(strict_types=1);

namespace App\Http\Health;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Fail = 'fail';
    /** The dependency is not configured in this environment (e.g. Scout not on Meilisearch). */
    case Skipped = 'skipped';
}

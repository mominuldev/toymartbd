<?php

declare(strict_types=1);

namespace App\Console\Commands\Launch;

/** Outcome of one launch-readiness check (docs/ops/LAUNCH-CHECKLIST.md). */
enum CheckStatus: string
{
    /** Verified, nothing to do. */
    case Pass = 'pass';

    /** Not a blocker by itself, but the owner must confirm it (a value that may still be a placeholder). `--strict` fails on it. */
    case Warn = 'warn';

    /** Launch blocker: the command exits non-zero. */
    case Fail = 'fail';

    /** Cannot be automated; a person verifies it and records the evidence in the checklist. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'PASS',
            self::Warn => 'WARN',
            self::Fail => 'FAIL',
            self::Manual => 'MANUAL',
        };
    }
}

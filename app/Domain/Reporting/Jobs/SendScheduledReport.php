<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Jobs;

use App\Domain\Reporting\Actions\SendReportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one claimed scheduled-report run on the `reports` queue — or, after a staff resend
 * (P9), the run's failed copies, or one recipient's (`userId`). One attempt only: a failed
 * copy is visible in the admin and resent from there, never retried automatically.
 */
final class SendScheduledReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $runId, public readonly ?int $userId = null)
    {
        $this->onQueue('reports');
    }

    public function handle(SendReportRun $send): void
    {
        $send->handle($this->runId, $this->userId);
    }
}

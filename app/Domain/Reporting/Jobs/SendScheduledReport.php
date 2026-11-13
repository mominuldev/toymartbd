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
 * Sends one claimed scheduled-report run on the `reports` queue. One attempt only: a retry
 * could mail the recipients who already got it; a failed run is visible in the admin instead.
 */
final class SendScheduledReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('reports');
    }

    public function handle(SendReportRun $send): void
    {
        $send->handle($this->runId);
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands\Notification;

use App\Domain\Notification\Actions\SendReviewRequests;
use Illuminate\Console\Command;

/** Review-request automation (P8): once per delivered order, N days after delivery. Scheduled hourly. */
final class SendReviewRequestsCommand extends Command
{
    protected $signature = 'toymart:automations:review-requests';

    protected $description = 'Queue one review request per delivered order (delay, consent and quiet hours permitting)';

    public function handle(SendReviewRequests $send): int
    {
        $tally = $send->handle();
        $this->info("Queued {$tally['queued']}, skipped {$tally['skipped']}.");

        return self::SUCCESS;
    }
}

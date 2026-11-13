<?php

declare(strict_types=1);

namespace App\Console\Commands\Notification;

use App\Domain\Notification\Actions\SendBirthdayMessages;
use Illuminate\Console\Command;

/** Birthday messages (P8): consented customers only, once a year. Scheduled daily at 10:00 Dhaka. */
final class SendBirthdayMessagesCommand extends Command
{
    protected $signature = 'toymart:automations:birthdays';

    protected $description = 'Queue a birthday message to each consented customer whose birthday is today (Dhaka)';

    public function handle(SendBirthdayMessages $send): int
    {
        $tally = $send->handle();
        $this->info("Queued {$tally['queued']}, skipped {$tally['skipped']}.");

        return self::SUCCESS;
    }
}

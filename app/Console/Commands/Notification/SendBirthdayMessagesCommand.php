<?php

declare(strict_types=1);

namespace App\Console\Commands\Notification;

use App\Domain\Notification\Actions\SendBirthdayMessages;
use Illuminate\Console\Command;

/** Birthday reminders (P8 batch 3): to consenting parents before each child's birthday, once a year each. Scheduled hourly; sends from 10:00 Dhaka outside quiet hours. */
final class SendBirthdayMessagesCommand extends Command
{
    protected $signature = 'toymart:automations:birthdays';

    protected $description = 'Queue a birthday reminder (with the offer, when on) to each consenting parent whose child\'s birthday is near (Dhaka)';

    public function handle(SendBirthdayMessages $send): int
    {
        $tally = $send->handle();
        $this->info("Queued {$tally['queued']}, skipped {$tally['skipped']}.");

        return self::SUCCESS;
    }
}

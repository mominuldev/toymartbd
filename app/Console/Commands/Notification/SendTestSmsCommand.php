<?php

declare(strict_types=1);

namespace App\Console\Commands\Notification;

use App\Domain\Notification\Actions\SendTestSms;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

/**
 * Sends ONE real SMS through the configured driver, so the owner can confirm a provider
 * account works (and, for REVE, see the success Status and whether Bangla arrives intact)
 * before customers depend on it. Uses the same settings (Admin → Integrations → Messaging,
 * env as the fallback) and the same SendTestSms action as the admin's "Send test SMS" card;
 * the send is recorded in notification_logs with its segments and cost like any other.
 */
final class SendTestSmsCommand extends Command
{
    protected $signature = 'toymart:sms:test {phone : A Bangladeshi mobile number, e.g. 01712345678}
                            {--bn : Send a Bangla test message instead of English}';

    protected $description = 'Send one test SMS through the configured SMS driver';

    public function handle(SendTestSms $send): int
    {
        $phone = PhoneNumber::tryParse((string) $this->argument('phone'));

        if ($phone === null) {
            $this->error('Not a valid Bangladeshi mobile number (013–019).');

            return self::INVALID;
        }

        $this->line('Driver: '.$send->driver().' · to '.$phone->masked());

        $result = $send->handle($phone, $this->option('bn') ? 'bn' : 'en');

        if (! $result->accepted) {
            $this->error('Refused: '.($result->error ?? 'unknown error'));

            return self::FAILURE;
        }

        $this->info("Accepted · message id {$result->providerMessageId} · {$result->segments} segment(s) · cost {$result->cost}");
        $this->line('Check the phone; if it did not arrive, look up the message id in the provider panel.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands\Notification;

use App\Domain\Notification\Contracts\SmsGateway;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

/**
 * Sends ONE real SMS through the configured driver, so the owner can confirm a provider
 * account works (and, for REVE, see the success Status and whether Bangla arrives intact)
 * before customers depend on it. Goes through the container's SmsGateway, so the send is
 * recorded in notification_logs with its segments and cost like any other.
 */
final class SendTestSmsCommand extends Command
{
    protected $signature = 'toymart:sms:test {phone : A Bangladeshi mobile number, e.g. 01712345678}
                            {--bn : Send a Bangla test message instead of English}';

    protected $description = 'Send one test SMS through the configured SMS driver';

    public function handle(SmsGateway $sms): int
    {
        $phone = PhoneNumber::tryParse((string) $this->argument('phone'));

        if ($phone === null) {
            $this->error('Not a valid Bangladeshi mobile number (013–019).');

            return self::INVALID;
        }

        $body = $this->option('bn')
            ? 'টয় মার্ট বিডি: এটি একটি পরীক্ষামূলক এসএমএস।'
            : 'Toy Mart BD: this is a test SMS.';

        $this->line('Driver: '.(string) config('notifications.sms.driver').' · to '.$phone->masked());

        $result = $sms->send($phone->e164(), $body, 'test');

        if (! $result->accepted) {
            $this->error('Refused: '.($result->error ?? 'unknown error'));

            return self::FAILURE;
        }

        $this->info("Accepted · message id {$result->providerMessageId} · {$result->segments} segment(s) · cost {$result->cost}");
        $this->line('Check the phone; if it did not arrive, look up the message id in the provider panel.');

        return self::SUCCESS;
    }
}

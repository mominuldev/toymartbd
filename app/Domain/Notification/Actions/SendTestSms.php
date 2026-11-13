<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\DTOs\SmsResult;
use App\Domain\Notification\Services\MessagingSettings;
use App\Support\PhoneNumber;

/**
 * Sends ONE test SMS through the configured driver — the "Send test SMS" card at Admin →
 * Integrations → Messaging and `php artisan toymart:sms:test` both call this, so they use
 * the same settings and the same message.
 *
 * Goes through the container's SmsGateway (RecordingSmsGateway), so the send lands in
 * notification_logs with its segments and cost like any other. The provider call is an
 * external HTTP request: this runs outside any transaction (rule 4) — no DB::transaction
 * here or in a caller.
 */
final readonly class SendTestSms
{
    public const string TEMPLATE = 'test';

    public const array BODIES = [
        'en' => 'Toy Mart BD: this is a test SMS.',
        'bn' => 'টয় মার্ট বিডি: এটি একটি পরীক্ষামূলক এসএমএস।',
    ];

    public function __construct(private SmsGateway $sms, private MessagingSettings $settings) {}

    public function handle(PhoneNumber $phone, string $locale): SmsResult
    {
        $body = self::BODIES[$locale] ?? self::BODIES['en'];

        return $this->sms->send($phone->e164(), $body, self::TEMPLATE);
    }

    public function driver(): string
    {
        return $this->settings->smsDriver();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Notification\Drivers;

use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\DTOs\SmsResult;
use App\Domain\Notification\Support\SmsSegments;
use App\Domain\Notification\Support\SmsTariff;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Default driver for local and testing: sends nothing, records a masked line in the log
 * and reports success with the configured (placeholder) tariff so cost accounting is
 * exercised end to end. Never logs the body — an OTP in a log file is a credential.
 */
final class LogSmsGateway implements SmsGateway
{
    public function __construct(private readonly SmsTariff $tariff) {}

    public function send(string $e164Phone, string $body, string $template): SmsResult
    {
        $segments = SmsSegments::count($body);

        Log::channel(config('notifications.sms.log_channel', config('logging.default')))->info('sms.log_driver', [
            'to' => PhoneNumber::tryParse($e164Phone)?->masked() ?? '***',
            'template' => $template,
            'segments' => $segments,
            'encoding' => SmsSegments::encoding($body),
        ]);

        return SmsResult::accepted('log-'.Str::uuid()->toString(), $segments, $this->tariff->costFor($segments));
    }
}

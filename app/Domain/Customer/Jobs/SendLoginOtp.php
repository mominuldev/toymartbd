<?php

declare(strict_types=1);

namespace App\Domain\Customer\Jobs;

use App\Domain\Customer\Services\OtpCodes;
use App\Domain\Notification\Contracts\SmsGateway;
use App\Support\PhoneNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Texts a sign-in code (P4-STO-11). SMS only — never WhatsApp (rule 32b). Queued after
 * the OTP row commits (rule 4: no external call inside a transaction) and encrypted on
 * the queue, because the payload carries the plain code. Expired codes are not retried:
 * a code that arrives after it expires is useless.
 */
final class SendLoginOtp implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string TEMPLATE = 'otp.login';

    public int $tries = 2;

    public function __construct(
        public readonly string $e164Phone,
        public readonly string $code,
        public readonly string $locale,
        public readonly int $expiresAtTimestamp,
    ) {
        $this->onQueue('notify');
    }

    public function handle(SmsGateway $sms): void
    {
        if (time() >= $this->expiresAtTimestamp) {
            return;
        }

        $body = (string) __('account.otp.sms', ['code' => $this->code, 'minutes' => intdiv(OtpCodes::TTL_SECONDS, 60)], $this->locale);
        $result = $sms->send($this->e164Phone, $body, self::TEMPLATE);

        if (! $result->accepted) {
            // Masked phone only (rule 20); never the body — it holds the code.
            Log::warning('Sign-in code SMS rejected', [
                'phone' => PhoneNumber::tryParse($this->e164Phone)?->masked() ?? '***',
                'error' => $result->error,
            ]);
        }
    }
}

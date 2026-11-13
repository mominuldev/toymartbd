<?php

declare(strict_types=1);

use App\Domain\Customer\Jobs\SendLoginOtp;
use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\Drivers\SmsNetBdGateway;
use App\Domain\Notification\DTOs\SmsResult;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Phase 9 coverage (docs/audits/SEO-AND-COVERAGE.md, Task B): SMS error paths that had no
 * test — the unverified sms.net.bd driver refusing every send without touching the network
 * (rule 33a spirit: never guess a provider contract), and the sign-in code job's skip and
 * rejection branches (rule 20: a masked phone in the log, never the code).
 */

/** A gateway that records calls and answers with a fixed result. */
function smsFailureGateway(SmsResult $result): SmsGateway
{
    return new class($result) implements SmsGateway
    {
        /** @var list<array{string, string, string}> */
        public array $sent = [];

        public function __construct(private readonly SmsResult $result) {}

        public function send(string $e164Phone, string $body, string $template): SmsResult
        {
            $this->sent[] = [$e164Phone, $body, $template];

            return $this->result;
        }
    };
}

it('refuses every send through the unverified sms.net.bd driver, with no HTTP call', function (bool $verified): void {
    Http::preventStrayRequests();
    config(['notifications.sms.drivers.sms_net_bd.verified' => $verified]);

    $result = (new SmsNetBdGateway)->send('+8801712345678', 'Toy Mart BD: hello', 'test');

    expect($result->accepted)->toBeFalse()
        ->and($result->providerMessageId)->toBeNull()
        ->and($result->segments)->toBe(1)
        ->and($result->error)->toContain('sms_net_bd');
    Http::assertNothingSent();
})->with(['unverified' => [false], 'flag flipped before the driver exists' => [true]]);

it('queues the sign-in code encrypted, on the notify queue', function (): void {
    $job = new SendLoginOtp('+8801712345678', '123456', 'en', time() + 300);

    expect($job)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job->queue)->toBe('notify');
});

it('does not text a sign-in code that has already expired', function (): void {
    $gateway = smsFailureGateway(SmsResult::accepted('m-1', 1, App\Support\Money::zero()));

    (new SendLoginOtp('+8801712345678', '123456', 'en', time() - 1))->handle($gateway);

    expect($gateway->sent)->toBe([]);
});

it('logs a rejected sign-in SMS with the phone masked and never the code', function (string $locale): void {
    Log::spy();
    $gateway = smsFailureGateway(SmsResult::rejected('provider said no'));

    (new SendLoginOtp('+8801712345678', '482913', $locale, time() + 300))->handle($gateway);

    expect($gateway->sent)->toHaveCount(1)
        ->and($gateway->sent[0][1])->toContain('482913')
        ->and($gateway->sent[0][2])->toBe(SendLoginOtp::TEMPLATE);

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
        $flat = json_encode($context, JSON_THROW_ON_ERROR);

        return $message === 'Sign-in code SMS rejected'
            && $context['phone'] === '017****5678'
            && $context['error'] === 'provider said no'
            && ! str_contains($flat, '482913')
            && ! str_contains($flat, '1712345678');
    });
})->with(['en', 'bn']);

<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront\Account;

use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\DTOs\SmsResult;
use App\Support\Money;
use RuntimeException;

/** Records every SMS; never calls a provider. */
final class FakeSms implements SmsGateway
{
    /** @var list<array{phone: string, body: string, template: string}> */
    public array $sent = [];

    public function send(string $e164Phone, string $body, string $template): SmsResult
    {
        $this->sent[] = ['phone' => $e164Phone, 'body' => $body, 'template' => $template];

        return SmsResult::accepted('fake-'.count($this->sent), 1, Money::zero());
    }

    /** The 6-digit code in the latest SMS. */
    public function lastCode(): string
    {
        $last = end($this->sent);
        if ($last === false || preg_match('/\b(\d{6})\b/', $last['body'], $m) !== 1) {
            throw new RuntimeException('No code was texted.');
        }

        return $m[1];
    }
}

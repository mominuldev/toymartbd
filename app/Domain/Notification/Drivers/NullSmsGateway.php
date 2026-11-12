<?php

declare(strict_types=1);

namespace App\Domain\Notification\Drivers;

use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\DTOs\SmsResult;
use App\Domain\Notification\Support\SmsSegments;
use App\Support\Money;

/** Silently accepts everything at zero cost. For tests that do not care about SMS. */
final class NullSmsGateway implements SmsGateway
{
    public function send(string $e164Phone, string $body, string $template): SmsResult
    {
        return SmsResult::accepted(null, SmsSegments::count($body), Money::zero());
    }
}

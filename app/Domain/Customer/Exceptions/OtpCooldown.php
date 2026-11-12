<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exceptions;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * A code was sent to this phone too recently, or too many this hour. A 429
 * `too-many-requests` problem carrying `Retry-After`.
 */
final class OtpCooldown extends TooManyRequestsHttpException
{
    public function __construct(int $retryAfter)
    {
        parent::__construct($retryAfter, (string) __('account.otp.cooldown', ['seconds' => $retryAfter]));
    }
}

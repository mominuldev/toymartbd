<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Exceptions;

use RuntimeException;

/**
 * "Continue with Facebook" could not finish. `$retryable` is true when Meta could not be reached
 * (the controller answers 503); otherwise something on our side or Meta's answer is wrong — the
 * Meta app is not set up, the login expired, Meta refused the code — and it is a 409. The
 * message is shown to the owner; it never carries a token or the app secret.
 */
final class MetaConnectionFailed extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}

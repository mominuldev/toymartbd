<?php

declare(strict_types=1);

namespace App\Domain\Ai\Exceptions;

use RuntimeException;

/**
 * An AI call that did not produce a usable answer. `reason` is stable and machine-readable; the
 * HTTP layer maps it to an RFC 9457 problem (rule 18). Messages never carry a key or a prompt.
 */
final class AiException extends RuntimeException
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string CAP_REACHED = 'cap_reached';

    public const string AUTH = 'auth_failed';

    public const string RATE_LIMITED = 'rate_limited';

    public const string PROVIDER = 'provider_error';

    public const string REFUSED = 'refused';

    public const string INVALID_OUTPUT = 'invalid_output';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}

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

    /** The owner switched off sharing what this feature needs (Integrations › AI assistant › What the AI can see). */
    public const string NOT_SHARED = 'not_shared';

    public const string AUTH = 'auth_failed';

    public const string RATE_LIMITED = 'rate_limited';

    /**
     * The key's plan has no quota for this model at all (a free Gemini key on a Pro model, an
     * OpenAI account without credit). Unlike a rate limit, waiting does not help.
     */
    public const string NO_QUOTA = 'no_quota';

    public const string PROVIDER = 'provider_error';

    public const string REFUSED = 'refused';

    public const string INVALID_OUTPUT = 'invalid_output';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}

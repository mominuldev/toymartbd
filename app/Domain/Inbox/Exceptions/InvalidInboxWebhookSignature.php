<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** An inbox webhook whose X-Hub-Signature-256 did not verify: nothing parsed, nothing written (rule 19). 401. */
final class InvalidInboxWebhookSignature extends HttpException
{
    public static function make(): self
    {
        return new self(401, 'The webhook signature is not valid.');
    }
}

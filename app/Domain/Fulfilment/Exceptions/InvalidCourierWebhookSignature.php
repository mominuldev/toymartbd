<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A courier webhook whose signature did not verify (rule 19). Nothing was parsed and
 * nothing was written. Rendered as a 401 problem.
 */
final class InvalidCourierWebhookSignature extends HttpException
{
    /** @param  array<string, string>  $headers */
    public static function make(array $headers = []): self
    {
        return new self(401, 'The webhook signature is not valid.', null, $headers);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Payment\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A provider notification whose signature did not verify (rule 19). Nothing was parsed
 * into a domain object and nothing was written. Rendered as a 401 problem.
 */
final class InvalidWebhookSignature extends HttpException
{
    public static function make(): self
    {
        return new self(401, 'The notification signature is not valid.');
    }
}

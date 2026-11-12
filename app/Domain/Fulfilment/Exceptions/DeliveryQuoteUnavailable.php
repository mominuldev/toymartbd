<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * No active courier provider holds a rate card, so no charge can be quoted. A
 * configuration problem, not the customer's: rendered as a 503 problem, and the
 * storefront falls back to its Error state.
 */
final class DeliveryQuoteUnavailable extends ServiceUnavailableHttpException
{
    public static function noProvider(): self
    {
        return new self(null, 'No active courier provider is configured for delivery quotes.');
    }
}

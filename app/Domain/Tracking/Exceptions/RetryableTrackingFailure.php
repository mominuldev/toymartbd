<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Exceptions;

use RuntimeException;

/**
 * A transport failure, 429 or 5xx from a tracking provider. The dispatch job catches
 * nothing of this — it lets the queue retry. The message never contains a secret.
 */
final class RetryableTrackingFailure extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $destination,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use RuntimeException;

/**
 * A compliance certificate (or media-library PDF) upload the server refuses; the message
 * is written for staff. `reason` is a stable code (`empty`, `too_large`, `wrong_type`,
 * `active_content`) with `params`, for the library's localised messages.
 */
final class InvalidCertificate extends RuntimeException
{
    /** @param array<string, string|int> $params */
    public function __construct(
        string $message = '',
        public readonly string $reason = 'wrong_type',
        public readonly array $params = [],
    ) {
        parent::__construct($message);
    }
}

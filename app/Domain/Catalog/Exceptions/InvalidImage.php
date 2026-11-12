<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An upload the media pipeline refuses (wrong real type, too large, polyglot, not
 * decodable). The message is written for staff. Upload validation and the upload
 * Actions turn it into a 422 on `file`.
 *
 * `reason` is a stable code (`empty`, `too_large`, `wrong_type`, `payload`, `unreadable`,
 * `too_wide`, `undecodable`) with `params`, so the media library can show a localised
 * message (lang/{en,bn}/media.php) without parsing the English one.
 */
final class InvalidImage extends RuntimeException
{
    /** @param array<string, string|int> $params */
    public function __construct(
        string $message = '',
        public readonly string $reason = 'unreadable',
        public readonly array $params = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

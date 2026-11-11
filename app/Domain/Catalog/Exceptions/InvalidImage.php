<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use RuntimeException;

/**
 * An upload the media pipeline refuses (wrong real type, too large, polyglot, not
 * decodable). The message is written for staff. Upload validation and the upload
 * Actions turn it into a 422 on `file`.
 */
final class InvalidImage extends RuntimeException {}

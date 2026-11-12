<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use RuntimeException;

/** A compliance certificate upload the server refuses; the message is written for staff. */
final class InvalidCertificate extends RuntimeException {}

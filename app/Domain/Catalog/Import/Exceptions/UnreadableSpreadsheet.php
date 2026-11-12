<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import\Exceptions;

use RuntimeException;

/** The file as a whole cannot be imported (unreadable, no SKU column, unknown columns, too many rows). */
final class UnreadableSpreadsheet extends RuntimeException {}

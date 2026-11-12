<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import\Enums;

/**
 * A product import's life (P4-CAT-10): the file is checked in full first (preview, a
 * dry run whose writes are rolled back), then committed only on confirmation.
 */
enum ProductImportStatus: string
{
    case Queued = 'queued';
    case Previewing = 'previewing';
    case Previewed = 'previewed';
    case Importing = 'importing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isRunning(): bool
    {
        return in_array($this, [self::Queued, self::Previewing, self::Importing], true);
    }
}

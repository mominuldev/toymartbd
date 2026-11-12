<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import\Enums;

/** What an import row did (or, in the preview, would do). */
enum RowOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
}

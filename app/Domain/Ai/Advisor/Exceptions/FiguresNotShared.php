<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Exceptions;

use RuntimeException;

/**
 * Integrations › AI assistant has "report figures" switched off. The advisor reads nothing but
 * figures, so it refuses instead of guessing; the HTTP layer answers 409.
 */
final class FiguresNotShared extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Growth advisor needs report figures. Allow "Report figures" in Integrations › AI assistant to use it.');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Inventory\Models\StockLocation;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A branch-mode rule refused a change (409):
 * - {@see self::blocked()}: switching to a single shop while other locations are active —
 *   `blockers` lists them (rendered as `branch-mode-blocked`);
 * - {@see self::singleMode()}: adding a location, or switching one back on, while the store
 *   runs as a single shop (rendered as `single-branch-mode`).
 * The HTTP layer picks the problem type from {@see self::$blocking}; the domain never imports it.
 */
final class BranchModeConflict extends ConflictHttpException
{
    /**
     * @param  list<StockLocation>  $blockers
     */
    private function __construct(string $message, public readonly bool $blocking, public readonly array $blockers = [])
    {
        parent::__construct($message);
    }

    /** @param  list<StockLocation>  $blockers */
    public static function blocked(array $blockers): self
    {
        return new self(trans_choice('store_settings.branch_mode_blocked', count($blockers), ['count' => count($blockers)]), true, $blockers);
    }

    public static function singleMode(): self
    {
        return new self((string) __('store_settings.branch_mode_single'), false);
    }
}

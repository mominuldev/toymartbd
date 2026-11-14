<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A branch / warehouse change refused because stock or sales depend on the location:
 * deactivating the default location (opening stock, sales and the storefront read it), or
 * one that still holds, reserves or expects stock (transfer it out first). Rendered as a
 * 409 `conflict` problem with the message as `detail`. Locations are never deleted.
 */
final class StockLocationConflict extends ConflictHttpException
{
    public static function defaultLocation(): self
    {
        return new self((string) __('store_settings.location_conflict_default'));
    }

    public static function holdsStock(): self
    {
        return new self((string) __('store_settings.location_conflict_stock'));
    }
}

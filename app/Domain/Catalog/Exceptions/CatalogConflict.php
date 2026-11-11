<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A catalog write refused because of the current state of other records — e.g.
 * archiving a category that still has active children, or deleting an attribute
 * value a variant uses. Rendered as a 409 `conflict` problem; the message is
 * written for staff and passed through as `detail`.
 *
 * Extends Symfony's HTTP exception (vendor, not App\Http) so the Domain stays free
 * of Http-layer imports while the API's problem renderer still maps it.
 */
final class CatalogConflict extends ConflictHttpException
{
    public static function categoryHasActiveChildren(): self
    {
        return new self('This category still has active subcategories. Archive or move them first.');
    }

    public static function parentArchived(): self
    {
        return new self('The parent category is archived. Restore or change the parent first.');
    }

    public static function valueInUse(): self
    {
        return new self('This value is used by product variants and cannot be deleted.');
    }

    public static function attributeInUse(): self
    {
        return new self('This attribute has values used by product variants and cannot be deleted.');
    }
}

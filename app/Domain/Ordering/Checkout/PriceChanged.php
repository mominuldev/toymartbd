<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Checkout;

use App\Support\Money;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The re-priced total differs from the one the customer was shown (SYSTEM-ARCHITECTURE
 * T6): the order is blocked with an explicit message, never silently re-priced. Nothing
 * has been written when this is thrown. Rendered as 409 `price-changed`.
 */
final class PriceChanged extends ConflictHttpException
{
    public function __construct(public readonly Money $expected, public readonly Money $actual)
    {
        parent::__construct('The order total has changed.');
    }

    /** Localised, for `errors[0].message` on `expected_total`. */
    public function customerMessage(): string
    {
        return (string) __('checkout.price_changed', ['total' => $this->actual->toDecimal()]);
    }
}

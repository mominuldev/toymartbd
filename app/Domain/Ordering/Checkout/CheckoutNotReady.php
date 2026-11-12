<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Checkout;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The cart cannot be ordered as it stands: empty, an item no longer sold, a coupon that
 * no longer applies (e.g. this phone's per-customer limit), or an address we do not
 * deliver to. Carries one localised message per field; rendered as a 422
 * `validation-error` (docs/api/checkout.md). Nothing has been written.
 */
final class CheckoutNotReady extends UnprocessableEntityHttpException
{
    /** @param  list<array{field: string, message: string}>  $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The cart cannot be checked out.');
    }
}

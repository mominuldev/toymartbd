<?php

declare(strict_types=1);

namespace App\Domain\Cart\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * No open cart has this token: never issued, expired, converted into an order or
 * merged into a customer's cart. A 404 `not-found` problem; the storefront drops its
 * cookie and starts a new cart.
 */
final class CartNotFound extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('Cart not found.');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * "Create an account" for a phone that already has one (owner decision 2026-11-20, accepting the
 * account-enumeration trade-off; the check is rate limited per phone and per IP). Served as a 409
 * `phone-already-registered` problem; the storefront offers Sign in and Forgot password.
 */
final class PhoneAlreadyRegistered extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct((string) __('account.register.exists'));
    }
}

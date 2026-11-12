<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** A blocked customer proved the phone but may not sign in. A 403 `forbidden` problem. */
final class CustomerBlocked extends AccessDeniedHttpException
{
    public function __construct()
    {
        parent::__construct((string) __('account.otp.blocked'));
    }
}

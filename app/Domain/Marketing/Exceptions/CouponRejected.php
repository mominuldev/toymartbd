<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Exceptions;

use App\Domain\Marketing\Enums\CouponRejection;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A coupon refused at the moment of use (CouponUsages::record — the limit re-check
 * under the coupon's row lock). Rendered as a 422 problem with the localised reason
 * as `detail`; `reason` is the stable code.
 */
final class CouponRejected extends UnprocessableEntityHttpException
{
    public function __construct(public readonly CouponRejection $reason, ?string $message = null)
    {
        parent::__construct($message ?? $reason->message());
    }
}

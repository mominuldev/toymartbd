<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A resend that would not be exact (P9): the run did not fail, the chosen recipient's copy
 * already went out, nothing is left to resend, or an older run has no per-recipient record
 * (so who already got it is unknown). Refused, nothing written (409).
 */
final class ReportRunNotResendable extends ConflictHttpException
{
    public static function notFailed(): self
    {
        return new self('Only a failed run can be resent.');
    }

    public static function alreadySent(): self
    {
        return new self('This recipient already received the report for this period.');
    }

    public static function nothingToResend(): self
    {
        return new self('Every recipient of this run already received it or is no longer allowed to.');
    }

    public static function unknownRecipients(): self
    {
        return new self('This run predates per-recipient records, so who already received it is unknown. Resend to one recipient instead.');
    }
}

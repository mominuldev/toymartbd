<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * Meta approval state of a WhatsApp template body, recorded by staff (no Meta API call is
 * made — no WhatsApp Business account exists yet). Editing the body of a `submitted` or
 * `approved` template drops it back to `draft`: the copy Meta approved is no longer the
 * copy stored here, and it must be resubmitted.
 */
enum WhatsAppApprovalStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}

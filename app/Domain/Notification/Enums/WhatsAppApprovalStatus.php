<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * Meta approval state of a WhatsApp template body. Set by staff by hand, or by "Sync from Meta"
 * (`POST /notifications/templates/whatsapp/sync`, which reads the WhatsApp Business Account's
 * template list). Editing the body of a `submitted` or `approved` template drops it back to
 * `draft`: the copy Meta approved is no longer the copy stored here, and it must be resubmitted.
 *
 * Only `approved` may be sent. `rejected`, `paused` and `disabled` are Meta's own outcomes
 * (template status enum on the WABA message_templates reference); they are recorded so staff
 * see why a template is not going out.
 */
enum WhatsAppApprovalStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paused = 'paused';
    case Disabled = 'disabled';

    /**
     * Meta's template `status` → ours. PENDING / IN_APPEAL are still with Meta; PENDING_DELETION,
     * DELETED, ARCHIVED and LIMIT_EXCEEDED cannot be sent, so they read as disabled. Anything
     * else (a value Meta adds later) is null: left alone, counted, never guessed.
     */
    public static function fromMeta(string $status): ?self
    {
        return match (strtoupper($status)) {
            'APPROVED' => self::Approved,
            'PENDING', 'IN_APPEAL' => self::Submitted,
            'REJECTED' => self::Rejected,
            'PAUSED' => self::Paused,
            'DISABLED', 'PENDING_DELETION', 'DELETED', 'ARCHIVED', 'LIMIT_EXCEEDED' => self::Disabled,
            default => null,
        };
    }

    /**
     * How far from sendable: lower is worse. A template's two languages (en, bn) combine to the
     * worse of the two — a Bangla update cannot go out if only the English copy is approved.
     */
    public function sendability(): int
    {
        return match ($this) {
            self::Disabled => 0,
            self::Rejected => 1,
            self::Paused => 2,
            self::Draft => 3,
            self::Submitted => 4,
            self::Approved => 5,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}

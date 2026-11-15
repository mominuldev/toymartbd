<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Enums;

/**
 * `text` is a real message on the channel and `template` an approved WhatsApp template we
 * sent (its body is our rendered preview of the approved copy). `note` is an internal note
 * and `system` a timeline line (assigned, resolved, reopened): both live only here and are
 * NEVER sent.
 */
enum MessageKind: string
{
    case Text = 'text';
    case Template = 'template';
    case Note = 'note';
    case System = 'system';

    public function leavesTheSystem(): bool
    {
        return $this === self::Text || $this === self::Template;
    }

    /** @return list<string> */
    public static function sentValues(): array
    {
        return [self::Text->value, self::Template->value];
    }
}

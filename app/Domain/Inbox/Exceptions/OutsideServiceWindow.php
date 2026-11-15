<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Exceptions;

use App\Domain\Inbox\Enums\ReplyState;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A free-form reply the conversation's reply state does not allow ({@see ReplyState}): a
 * WhatsApp thread past its 24-hour window (only an approved template may be sent — `POST
 * …/template`), or a Messenger thread past 24 hours without the human-agent switch, or past
 * 7 days. 409 `conflict`; nothing is queued.
 */
final class OutsideServiceWindow extends ConflictHttpException
{
    public static function make(ReplyState $state = ReplyState::TemplateOnly): self
    {
        return new self(__($state === ReplyState::Closed ? 'inbox.errors.reply_closed' : 'inbox.errors.outside_window'));
    }
}

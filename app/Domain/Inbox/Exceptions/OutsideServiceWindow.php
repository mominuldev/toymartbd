<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A free-form reply after Meta's 24-hour customer-service window closed. Outside it only an
 * approved template may be sent (WhatsApp) or a message tag used (Messenger) — neither is
 * offered from the inbox yet. 409 `conflict`; nothing is queued.
 */
final class OutsideServiceWindow extends ConflictHttpException
{
    public static function make(): self
    {
        return new self(__('inbox.errors.outside_window'));
    }
}

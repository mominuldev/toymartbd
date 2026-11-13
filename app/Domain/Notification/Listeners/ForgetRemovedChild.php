<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Customer\Events\CustomerChildRemoved;
use App\Domain\Notification\Actions\ForgetChildInMessages;

/**
 * A parent removed a child (P8 batch 3): the child's name leaves the birthday-reminder log
 * payloads, and a reminder still queued for that child is not sent.
 */
final readonly class ForgetRemovedChild
{
    public function __construct(private ForgetChildInMessages $forget) {}

    public function handle(CustomerChildRemoved $event): void
    {
        $this->forget->handle($event->childId);
    }
}

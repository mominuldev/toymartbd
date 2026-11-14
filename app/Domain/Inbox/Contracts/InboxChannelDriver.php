<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Contracts;

use App\Domain\Inbox\DTOs\OutboundMessage;
use App\Domain\Inbox\DTOs\SendResult;
use App\Domain\Inbox\Enums\InboxChannel;

/**
 * Sends one free-form reply on one channel (rule 5: a channel is a driver, never a branch in
 * inbox code). Like every gateway: called ONLY from the queued {@see \App\Domain\Inbox\Jobs\SendInboxMessage}
 * after commit, never inside a transaction (rule 4); a provider refusal is returned as a
 * rejection, a transport error is thrown so the job retries; never logs a full phone,
 * the message body or a credential (rule 20).
 */
interface InboxChannelDriver
{
    public function channel(): InboxChannel;

    /** Driver key as configured (`log`, `cloud`, `messenger`). */
    public function name(): string;

    /**
     * Has an owner connected a real account AND has a real message been seen end to end?
     * False for every driver today: no WhatsApp Business account or Facebook Page exists.
     */
    public function isVerified(): bool;

    public function send(OutboundMessage $message): SendResult;
}

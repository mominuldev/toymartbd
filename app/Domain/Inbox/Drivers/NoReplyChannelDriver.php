<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Drivers;

use App\Domain\Inbox\Contracts\InboxChannelDriver;
use App\Domain\Inbox\DTOs\OutboundMessage;
use App\Domain\Inbox\DTOs\SendResult;
use App\Domain\Inbox\Enums\InboxChannel;

/**
 * The driver of an inbound-only channel (the storefront contact form): there is nothing to
 * send through, so every send is refused — never "accepted" the way the log driver is. The
 * reply state already refuses before anything is queued ({@see \App\Domain\Inbox\Services\ReplyWindow});
 * this is the last guard should a queued id ever reach the job.
 */
final readonly class NoReplyChannelDriver implements InboxChannelDriver
{
    public function __construct(private InboxChannel $channel) {}

    public function channel(): InboxChannel
    {
        return $this->channel;
    }

    public function name(): string
    {
        return 'none';
    }

    public function isVerified(): bool
    {
        return false;
    }

    public function send(OutboundMessage $message): SendResult
    {
        return SendResult::rejected('no_send_channel', 'This channel is inbound only; reply by WhatsApp or a call.');
    }
}

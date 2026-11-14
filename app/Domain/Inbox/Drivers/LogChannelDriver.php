<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Drivers;

use App\Domain\Inbox\Contracts\InboxChannelDriver;
use App\Domain\Inbox\DTOs\OutboundMessage;
use App\Domain\Inbox\DTOs\SendResult;
use App\Domain\Inbox\Enums\InboxChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The default for every channel, locally and in CI: sends nothing and logs one line with the
 * message id and body length — never the recipient, never the body (rule 20).
 */
final readonly class LogChannelDriver implements InboxChannelDriver
{
    public function __construct(private InboxChannel $channel) {}

    public function channel(): InboxChannel
    {
        return $this->channel;
    }

    public function name(): string
    {
        return 'log';
    }

    public function isVerified(): bool
    {
        return false;
    }

    public function send(OutboundMessage $message): SendResult
    {
        Log::info('inbox.log_driver', [
            'channel' => $message->channel->value,
            'message_id' => $message->messageId,
            'length' => mb_strlen($message->body),
        ]);

        return SendResult::accepted('log-'.Str::uuid()->toString());
    }
}

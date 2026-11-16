<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\DTOs\InboundMessage;
use App\Domain\Inbox\Enums\ContactTopic;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Models\ConversationMessage;
use Illuminate\Support\Str;

/**
 * A "Send us a message" submission from the storefront Contact page (`POST
 * /storefront/contact-messages`, owner decision 2026-11-16) becomes an inbound message on the
 * `web_form` channel, through the same {@see IngestInboundMessage} every channel uses (its
 * transaction, the reopen line, `needs_human`, the customer linked by phone).
 *
 * The thread is keyed by the E.164 phone (`web:+8801…`), so every message from one phone joins
 * one conversation. Each submission gets a fresh id: two identical messages are two messages.
 * The channel is inbound only — staff answer by WhatsApp or a call ({@see \App\Domain\Inbox\Services\ReplyWindow}
 * reports `closed`).
 */
final readonly class ReceiveContactFormMessage
{
    public function __construct(private IngestInboundMessage $ingest) {}

    public function handle(string $name, string $phoneE164, ContactTopic $topic, ?string $orderNumber, string $message): ?ConversationMessage
    {
        return $this->ingest->handle(InboxChannel::WebForm, new InboundMessage(
            threadId: self::threadId($phoneE164),
            providerMessageId: 'web-'.Str::ulid(),
            body: self::body($topic, $orderNumber, $message),
            sentAt: now(),
            contactName: $name,
            phoneE164: $phoneE164,
        ));
    }

    public static function threadId(string $phoneE164): string
    {
        return 'web:'.$phoneE164;
    }

    /** "Topic: Delivery · Order: TMB-26-00042" then a blank line and the customer's words. */
    public static function body(ContactTopic $topic, ?string $orderNumber, string $message): string
    {
        $head = 'Topic: '.$topic->label();
        if ($orderNumber !== null && $orderNumber !== '') {
            $head .= ' · Order: '.$orderNumber;
        }

        return $head."\n\n".$message;
    }
}

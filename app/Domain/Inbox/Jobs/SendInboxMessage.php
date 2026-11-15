<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Jobs;

use App\Domain\Inbox\DTOs\OutboundMessage;
use App\Domain\Inbox\DTOs\OutboundTemplate;
use App\Domain\Inbox\Enums\DeliveryStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Enums\ReplyState;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Domain\Inbox\Services\InboxChannels;
use App\Domain\Inbox\Services\ReplyWindow;
use App\Domain\Notification\Contracts\WhatsAppTemplateLibrary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Sends one queued reply through the channel's driver — after commit, on the `notify` queue,
 * outside any transaction (rule 4). The payload is only the message id; the recipient is
 * re-read from the conversation, so no thread id or phone sits on the queue (rule 20).
 *
 * Only `kind = text|template, direction = out, status = queued` is ever sent: a note or a
 * system line cannot reach a driver even if its id were queued. The reply state is re-checked
 * here ({@see ReplyWindow}) — a reply queued at 23:59 that runs after the window closed fails
 * as `outside_window` rather than earning a provider error; on Messenger the tag is decided
 * now (`HUMAN_AGENT` only in the human-agent period, with the switch still on). A template is
 * sent only on WhatsApp in state `template_only` and only while the library still reports it
 * approved (`template_unavailable` otherwise). A thrown transport error is retried; after the
 * last try the message is `failed`.
 */
final class SendInboxMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public readonly int $messageId)
    {
        $this->afterCommit();
        $queue = config('inbox.queue', 'notify');
        $this->onQueue(is_string($queue) ? $queue : 'notify');
        $tries = config('inbox.tries', 3);
        $this->tries = is_int($tries) ? $tries : 3;
        $backoff = config('inbox.backoff', [30, 120]);
        $this->backoff = is_array($backoff) ? array_values(array_map(intval(...), $backoff)) : [30, 120];
    }

    public function handle(InboxChannels $channels, ReplyWindow $window, WhatsAppTemplateLibrary $library): void
    {
        $message = ConversationMessage::query()->with('conversation')->find($this->messageId);
        if ($message === null
            || ! in_array($message->kind, [MessageKind::Text, MessageKind::Template], true)
            || $message->direction !== MessageDirection::Out
            || $message->delivery_status !== DeliveryStatus::Queued
            || $message->body === null) {
            return;
        }

        $conversation = $message->conversation;
        $state = $window->for($conversation)->state;
        $template = null;
        $tag = null;

        if ($message->kind === MessageKind::Template) {
            if ($conversation->channel !== InboxChannel::WhatsApp || $state !== ReplyState::TemplateOnly) {
                $this->fail($message, 'template_not_allowed', 'A template is sent only on WhatsApp after the 24-hour window closed.');

                return;
            }
            $template = $this->template($message, $library);
            if ($template === null) {
                $this->fail($message, 'template_unavailable', 'The template is no longer approved for sending.');

                return;
            }
        } else {
            if (! $state->allowsFreeForm()) {
                $this->fail($message, 'outside_window', 'The customer-service window closed before the reply was sent.');

                return;
            }
            $tag = $conversation->channel === InboxChannel::Messenger ? $state->messengerTag() : null;
        }

        $message->attempts++;
        $message->meta = $message->kind === MessageKind::Text ? ($tag === null ? null : ['tag' => $tag]) : $message->meta;
        $message->save();

        $result = $channels->sender($conversation->channel)->send(new OutboundMessage(
            $conversation->channel,
            $message->id,
            $conversation->external_thread_id,
            $message->body,
            $template,
            $tag,
        ));

        if ($result->accepted) {
            $message->delivery_status = DeliveryStatus::Sent;
            $message->provider_message_id = $result->providerMessageId;
            $message->sent_at = now();
            $message->save();

            return;
        }

        $this->fail($message, (string) $result->code, $result->reason);
    }

    /** The stored template send, re-validated against the library: still approved, same parameter count. */
    private function template(ConversationMessage $message, WhatsAppTemplateLibrary $library): ?OutboundTemplate
    {
        $meta = $message->meta ?? [];
        $name = $meta['template'] ?? null;
        $language = $meta['language'] ?? null;
        $parameters = $meta['parameters'] ?? null;
        if (! is_string($name) || ! is_string($language) || ! is_array($parameters)) {
            return null;
        }
        $parameters = array_values(array_map(static fn (mixed $p): string => is_scalar($p) ? (string) $p : '', $parameters));

        $approved = $library->find($name, $language);
        if ($approved === null || count($approved->parameters) !== count($parameters)) {
            return null;
        }

        return new OutboundTemplate($approved->providerTemplate, $approved->language, $parameters);
    }

    public function failed(?Throwable $e): void
    {
        $message = ConversationMessage::query()->find($this->messageId);
        if ($message !== null && $message->delivery_status === DeliveryStatus::Queued) {
            $this->fail($message, 'transport', 'The channel could not be reached.');
        }
    }

    private function fail(ConversationMessage $message, string $code, ?string $reason): void
    {
        $message->delivery_status = DeliveryStatus::Failed;
        $message->failure_code = mb_substr($code, 0, 64);
        $message->failure_reason = $reason === null ? null : mb_substr($reason, 0, 300);
        $message->failed_at = now();
        $message->save();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Drivers;

use App\Domain\Inbox\Contracts\InboxChannelDriver;
use App\Domain\Inbox\Contracts\ReceivesWebhooks;
use App\Domain\Inbox\DTOs\InboundMessage;
use App\Domain\Inbox\DTOs\OutboundMessage;
use App\Domain\Inbox\DTOs\SendResult;
use App\Domain\Inbox\DTOs\StatusUpdate;
use App\Domain\Inbox\DTOs\WatermarkUpdate;
use App\Domain\Inbox\Enums\DeliveryStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Services\InboxChannelSettings;
use App\Domain\Inbox\Support\MetaSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Meta Messenger Platform — UNVERIFIED: no Facebook Page is connected (2026-11-15). Written
 * from Meta's published docs and tested only against hand-authored fixtures.
 *
 *  - Webhooks: same handshake and X-Hub-Signature-256 as WhatsApp; `object: page`,
 *    `entry[].messaging[]` with sender.id (PSID), recipient.id (page), timestamp,
 *    message.mid, message.text (developers.facebook.com/docs/messenger-platform/webhooks,
 *    …/reference/webhook-events/messages). `delivery.mids` + `delivery.watermark` and
 *    `read.watermark` (…/webhook-events/message-deliveries, …/message-reads).
 *  - Echoes (`message.is_echo`, our own sends coming back) are ignored.
 *  - The timestamp/watermark unit is ASSUMED to be milliseconds (the docs' examples are
 *    13-digit numbers; the page does not say so).
 *  - Send: POST {graph}/{version}/{page-id}/messages with {recipient: {id: PSID},
 *    messaging_type: RESPONSE, message: {text}}; answer {recipient_id, message_id}
 *    (…/messenger-platform/send-messages). Meta's examples pass the Page access token as an
 *    `access_token` query parameter; we send it as a Bearer header instead so it can never
 *    appear in a logged URL — ASSUMPTION: Graph accepts the header form here as it does
 *    elsewhere. Verify on the first real Page.
 *  - The standard messaging window is 24 hours. From 24 hours to 7 days a human reply may go
 *    with `messaging_type: MESSAGE_TAG, tag: HUMAN_AGENT` (…/send-messages/message-tags) —
 *    only when the inbox's human-agent switch is on ({@see \App\Domain\Inbox\Services\ReplyWindow}).
 *    Whether the tag needs Meta app review / a permission could NOT be confirmed.
 *  - The sender's name is not in the webhook (it needs a separate User Profile call, which
 *    is not built); a Messenger thread has no name and no phone until linked.
 */
final readonly class MessengerDriver implements InboxChannelDriver, ReceivesWebhooks
{
    public function __construct(private InboxChannelSettings $settings) {}

    public function channel(): InboxChannel
    {
        return InboxChannel::Messenger;
    }

    public function name(): string
    {
        return 'messenger';
    }

    public function isVerified(): bool
    {
        return false;
    }

    public function verifySubscription(?string $mode, ?string $verifyToken, ?string $challenge): ?string
    {
        return MetaSignature::handshake($mode, $verifyToken, $this->settings->get(InboxChannel::Messenger, 'verify_token'), $challenge);
    }

    public function verifySignature(array $headers, string $rawBody): bool
    {
        return MetaSignature::verify($headers, $rawBody, $this->settings->get(InboxChannel::Messenger, 'app_secret'));
    }

    /** @return list<InboundMessage|StatusUpdate|WatermarkUpdate> */
    public function parse(string $rawBody): array
    {
        $data = json_decode($rawBody, true);
        if (! is_array($data) || ($data['object'] ?? null) !== 'page' || ! is_array($data['entry'] ?? null)) {
            return [];
        }

        $events = [];
        foreach ($data['entry'] as $entry) {
            foreach ((is_array($entry) && is_array($entry['messaging'] ?? null)) ? $entry['messaging'] : [] as $event) {
                if (! is_array($event)) {
                    continue;
                }
                $sender = is_array($event['sender'] ?? null) && is_string($event['sender']['id'] ?? null) ? $event['sender']['id'] : null;
                if ($sender === null) {
                    continue;
                }

                $message = $event['message'] ?? null;
                if (is_array($message) && ($message['is_echo'] ?? false) !== true && is_string($message['mid'] ?? null)) {
                    $text = is_string($message['text'] ?? null) ? $message['text'] : null;
                    $events[] = new InboundMessage(
                        threadId: mb_substr($sender, 0, 191),
                        providerMessageId: mb_substr($message['mid'], 0, 191),
                        body: $text,
                        sentAt: self::ms($event['timestamp'] ?? null),
                        unsupportedType: $text === null ? (is_array($message['attachments'] ?? null) ? 'attachment' : 'unknown') : null,
                    );
                }

                $delivery = $event['delivery'] ?? null;
                if (is_array($delivery)) {
                    $at = self::ms($delivery['watermark'] ?? null);
                    foreach (is_array($delivery['mids'] ?? null) ? $delivery['mids'] : [] as $mid) {
                        if (is_string($mid)) {
                            $events[] = new StatusUpdate($mid, DeliveryStatus::Delivered, $at);
                        }
                    }
                    if (isset($delivery['watermark'])) {
                        $events[] = new WatermarkUpdate($sender, DeliveryStatus::Delivered, $at);
                    }
                }

                $read = $event['read'] ?? null;
                if (is_array($read) && isset($read['watermark'])) {
                    $events[] = new WatermarkUpdate($sender, DeliveryStatus::Read, self::ms($read['watermark']));
                }
            }
        }

        return $events;
    }

    public function send(OutboundMessage $message): SendResult
    {
        $pageId = $this->settings->get(InboxChannel::Messenger, 'page_id');
        $token = $this->settings->get(InboxChannel::Messenger, 'page_access_token');
        if ($pageId === null || $token === null) {
            return SendResult::rejected('not_configured', 'Messenger page id or page access token is not set.');
        }

        $url = rtrim((string) config('inbox.graph_base_url'), '/').'/'.config('inbox.graph_version').'/'.rawurlencode($pageId).'/messages';

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('inbox.timeout', 10))
                ->post($url, [
                    'recipient' => ['id' => $message->recipient],
                    // HUMAN_AGENT (24 h – 7 days after the user's last message) goes as a message tag.
                    ...($message->tag === null ? ['messaging_type' => 'RESPONSE'] : ['messaging_type' => 'MESSAGE_TAG', 'tag' => $message->tag]),
                    'message' => ['text' => $message->body],
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Messenger Send API unreachable.');
        }

        if ($response->successful()) {
            $id = $response->json('message_id');

            return is_string($id) && $id !== ''
                ? SendResult::accepted($id)
                : SendResult::rejected('provider_error', 'Accepted without a message id.');
        }

        $code = $response->json('error.code');
        $code = is_int($code) || is_string($code) ? (string) $code : (string) $response->status();
        Log::warning('inbox.messenger.send_rejected', ['message_id' => $message->messageId, 'status' => $response->status(), 'code' => $code]);

        if ($response->serverError() || $response->status() === 429) {
            throw new RuntimeException("Messenger Send API answered {$response->status()}.");
        }

        $title = $response->json('error.message');

        return SendResult::rejected('provider_error:'.$code, is_string($title) ? $title : 'Messenger refused the message.');
    }

    private static function ms(mixed $value): Carbon
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value)) || is_float($value)) {
            $ms = (int) $value;
            if ($ms > 1_000_000_000_000 && $ms < 10_000_000_000_000) {
                return Carbon::createFromTimestampMsUTC($ms);
            }
        }

        return now();
    }
}

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
use App\Support\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Meta WhatsApp Cloud API — UNVERIFIED: no WhatsApp Business account exists (2026-11-15).
 * Written from Meta's published docs and tested only against hand-authored fixtures.
 *
 *  - Webhook handshake and X-Hub-Signature-256: {@see MetaSignature}.
 *  - Inbound `messages` and `statuses` shapes: the "messages webhook reference"
 *    (developers.facebook.com/docs/whatsapp/cloud-api/webhooks/payload-examples) and
 *    …/whatsapp/webhooks/reference/messages/status.
 *  - Send: POST {graph}/{version}/{phone-number-id}/messages, Bearer token, body
 *    {messaging_product, recipient_type, to, type: text, text: {body}}; the answer
 *    `messages[0].id` (a wamid) only means "accepted", not delivered
 *    (…/whatsapp/messages/text-messages, …/cloud-api/guides/send-messages).
 *  - Error 131047: "More than 24 hours have passed since the recipient last replied" — the
 *    customer-service window (…/cloud-api/support/error-codes). Inbox code refuses a reply
 *    outside the window BEFORE queueing; this mapping is the backstop.
 *  - Text body max 4096 characters (…/whatsapp/messages/text-messages).
 *  - Template send (outside the window): the same endpoint with `type: template`,
 *    `template: {name, language: {code}, components: [{type: body, parameters: [{type: text,
 *    text}]}]}` (…/whatsapp/cloud-api/guides/send-message-templates). Positional parameters,
 *    {{1}} first; no `components` when the template has none.
 *
 * Users who adopted a WhatsApp username may arrive without `from`/`wa_id` and only a BSUID
 * (`from_user_id`); the thread is then keyed on the BSUID and has no phone.
 */
final readonly class WhatsAppCloudDriver implements InboxChannelDriver, ReceivesWebhooks
{
    public const int MAX_BODY = 4096;

    public function __construct(private InboxChannelSettings $settings) {}

    public function channel(): InboxChannel
    {
        return InboxChannel::WhatsApp;
    }

    public function name(): string
    {
        return 'cloud';
    }

    public function isVerified(): bool
    {
        return false;
    }

    public function verifySubscription(?string $mode, ?string $verifyToken, ?string $challenge): ?string
    {
        return MetaSignature::handshake($mode, $verifyToken, $this->settings->get(InboxChannel::WhatsApp, 'verify_token'), $challenge);
    }

    public function verifySignature(array $headers, string $rawBody): bool
    {
        return MetaSignature::verify($headers, $rawBody, $this->settings->get(InboxChannel::WhatsApp, 'app_secret'));
    }

    /** @return list<InboundMessage|StatusUpdate|WatermarkUpdate> */
    public function parse(string $rawBody): array
    {
        $data = json_decode($rawBody, true);
        if (! is_array($data) || ($data['object'] ?? null) !== 'whatsapp_business_account' || ! is_array($data['entry'] ?? null)) {
            return [];
        }

        $events = [];
        foreach ($data['entry'] as $entry) {
            foreach ((is_array($entry) && is_array($entry['changes'] ?? null)) ? $entry['changes'] : [] as $change) {
                if (! is_array($change) || ($change['field'] ?? null) !== 'messages' || ! is_array($change['value'] ?? null)) {
                    continue;
                }
                array_push($events, ...$this->parseValue($change['value']));
            }
        }

        return $events;
    }

    public function send(OutboundMessage $message): SendResult
    {
        $phoneNumberId = $this->settings->get(InboxChannel::WhatsApp, 'phone_number_id');
        $token = $this->settings->get(InboxChannel::WhatsApp, 'access_token');
        if ($phoneNumberId === null || $token === null) {
            return SendResult::rejected('not_configured', 'WhatsApp Cloud API phone number id or access token is not set.');
        }

        $url = rtrim((string) config('inbox.graph_base_url'), '/').'/'.config('inbox.graph_version').'/'.rawurlencode($phoneNumberId).'/messages';

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('inbox.timeout', 10))
                ->post($url, self::payload($message));
        } catch (ConnectionException) {
            // Retryable. The exception text can carry the URL; never rethrow it as is.
            throw new RuntimeException('WhatsApp Cloud API unreachable.');
        }

        if ($response->successful()) {
            $id = $response->json('messages.0.id');

            return is_string($id) && $id !== ''
                ? SendResult::accepted($id)
                : SendResult::rejected('provider_error', 'Accepted without a message id.');
        }

        $code = $response->json('error.code');
        $code = is_int($code) || is_string($code) ? (string) $code : (string) $response->status();
        Log::warning('inbox.whatsapp.send_rejected', ['message_id' => $message->messageId, 'status' => $response->status(), 'code' => $code]);

        if ($response->serverError() || $response->status() === 429) {
            throw new RuntimeException("WhatsApp Cloud API answered {$response->status()}.");
        }

        return $code === '131047'
            ? SendResult::rejected('outside_window', 'The 24-hour customer-service window has closed; only an approved template can be sent.')
            : SendResult::rejected('provider_error:'.$code, $this->errorTitle($response->json('error')));
    }

    /** @return array<string, mixed> */
    private static function payload(OutboundMessage $message): array
    {
        $base = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $message->recipient];

        if ($message->template !== null) {
            $template = ['name' => $message->template->name, 'language' => ['code' => $message->template->language]];
            if ($message->template->parameters !== []) {
                $template['components'] = [[
                    'type' => 'body',
                    'parameters' => array_map(static fn (string $text): array => ['type' => 'text', 'text' => $text], $message->template->parameters),
                ]];
            }

            return [...$base, 'type' => 'template', 'template' => $template];
        }

        return [...$base, 'type' => 'text', 'text' => ['preview_url' => true, 'body' => mb_substr($message->body, 0, self::MAX_BODY)]];
    }

    /**
     * @param  array<mixed>  $value
     * @return list<InboundMessage|StatusUpdate>
     */
    private function parseValue(array $value): array
    {
        $names = [];
        foreach (is_array($value['contacts'] ?? null) ? $value['contacts'] : [] as $contact) {
            if (! is_array($contact)) {
                continue;
            }
            $id = $contact['wa_id'] ?? $contact['user_id'] ?? null;
            $name = is_array($contact['profile'] ?? null) ? ($contact['profile']['name'] ?? null) : null;
            if (is_string($id) && is_string($name)) {
                $names[$id] = mb_substr($name, 0, 120);
            }
        }

        $events = [];
        foreach (is_array($value['messages'] ?? null) ? $value['messages'] : [] as $m) {
            if (! is_array($m)) {
                continue;
            }
            $from = is_string($m['from'] ?? null) && $m['from'] !== '' ? $m['from'] : null;
            $thread = $from ?? (is_string($m['from_user_id'] ?? null) ? $m['from_user_id'] : null);
            $id = $m['id'] ?? null;
            if ($thread === null || ! is_string($id) || $id === '') {
                continue;
            }
            $type = is_string($m['type'] ?? null) ? $m['type'] : 'unknown';
            $body = $type === 'text' && is_array($m['text'] ?? null) && is_string($m['text']['body'] ?? null) ? $m['text']['body'] : null;

            $events[] = new InboundMessage(
                threadId: mb_substr($thread, 0, 191),
                providerMessageId: mb_substr($id, 0, 191),
                body: $body,
                sentAt: self::time($m['timestamp'] ?? null),
                contactName: $names[$thread] ?? null,
                phoneE164: $from !== null && ctype_digit($from) ? PhoneNumber::tryParse('+'.$from)?->e164() : null,
                unsupportedType: $type === 'text' ? null : mb_substr($type, 0, 32),
            );
        }

        foreach (is_array($value['statuses'] ?? null) ? $value['statuses'] : [] as $s) {
            if (! is_array($s) || ! is_string($s['id'] ?? null)) {
                continue;
            }
            $status = match ($s['status'] ?? null) {
                'sent' => DeliveryStatus::Sent,
                'delivered' => DeliveryStatus::Delivered,
                'read' => DeliveryStatus::Read,
                'failed' => DeliveryStatus::Failed,
                default => null, // `played` (voice) and anything new: ignored.
            };
            if ($status === null) {
                continue;
            }
            $error = is_array($s['errors'] ?? null) && is_array($s['errors'][0] ?? null) ? $s['errors'][0] : [];
            $events[] = new StatusUpdate(
                $s['id'],
                $status,
                self::time($s['timestamp'] ?? null),
                isset($error['code']) && (is_int($error['code']) || is_string($error['code'])) ? (string) $error['code'] : null,
                is_string($error['title'] ?? null) ? mb_substr($error['title'], 0, 300) : null,
            );
        }

        return $events;
    }

    /** Unix seconds (Meta sends them as a string); a missing or absurd value means "now". */
    private static function time(mixed $value): Carbon
    {
        if ((is_string($value) && ctype_digit($value)) || is_int($value)) {
            $seconds = (int) $value;
            if ($seconds > 1_000_000_000 && $seconds < 10_000_000_000) {
                return Carbon::createFromTimestampUTC($seconds);
            }
        }

        return now();
    }

    private function errorTitle(mixed $error): string
    {
        if (is_array($error)) {
            $title = $error['error_user_title'] ?? $error['title'] ?? $error['message'] ?? null;
            if (is_string($title)) {
                return $title;
            }
        }

        return 'WhatsApp refused the message.';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Exceptions\InvalidInboxWebhookSignature;
use App\Domain\Inbox\Jobs\ProcessInboxWebhook;
use App\Domain\Inbox\Models\InboxWebhookReceipt;
use App\Domain\Inbox\Services\InboxChannels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An inbox webhook (WhatsApp Cloud API, Messenger). In this order, without exception:
 *
 *   1. Resolve the channel; one that takes no webhooks → 404, nothing written.
 *   2. VERIFY X-Hub-Signature-256 on the raw bytes. Invalid or missing, or no app secret
 *      configured → 401; nothing parsed, nothing written (rule 19).
 *   3. Record the verified body once (UNIQUE channel + sha256): a byte-identical re-delivery
 *      is a no-op.
 *   4. Queue {@see ProcessInboxWebhook} to parse and apply it; answer 200 fast (Meta retries
 *      on anything else). Message-level idempotency is the provider message id.
 *
 * No transaction: one idempotent insert, then a dispatch.
 */
final readonly class ReceiveInboxWebhook
{
    public function __construct(private InboxChannels $channels) {}

    /**
     * @param  array<string, list<string|null>|string|null>  $headers  lower-cased names
     * @return array{replayed: bool, receipt_id: int}
     */
    public function handle(string $channelKey, array $headers, string $rawBody): array
    {
        $channel = InboxChannel::tryFrom($channelKey);
        $receiver = $channel === null ? null : $this->channels->receiver($channel);
        if ($channel === null || $receiver === null) {
            throw new NotFoundHttpException;
        }

        if (! $receiver->verifySignature($headers, $rawBody)) {
            Log::warning('inbox.webhook.signature_invalid', ['channel' => $channel->value, 'bytes' => strlen($rawBody)]);

            throw InvalidInboxWebhookSignature::make();
        }

        $hash = hash('sha256', $rawBody);
        $inserted = InboxWebhookReceipt::query()->insertOrIgnore([[
            'channel' => $channel->value,
            'body_sha256' => $hash,
            'payload' => mb_substr($rawBody, 0, 1_000_000),
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        /** @var InboxWebhookReceipt $receipt */
        $receipt = InboxWebhookReceipt::query()->where('channel', $channel->value)->where('body_sha256', $hash)->firstOrFail();
        if ($receipt->processed_at === null) {
            ProcessInboxWebhook::dispatch($receipt->id);
        }

        return ['replayed' => $inserted === 0, 'receipt_id' => $receipt->id];
    }

    /** The GET handshake: the challenge to echo, or null (→ 403). */
    public function subscribe(string $channelKey, ?string $mode, ?string $token, ?string $challenge): ?string
    {
        $channel = InboxChannel::tryFrom($channelKey);
        $receiver = $channel === null ? null : $this->channels->receiver($channel);
        if ($receiver === null) {
            throw new NotFoundHttpException;
        }

        return $receiver->verifySubscription($mode, $token, $challenge);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Contracts\CourierGateway;
use App\Domain\Fulfilment\Contracts\ShapesWebhookResponse;
use App\Domain\Fulfilment\Exceptions\InvalidCourierWebhookSignature;
use App\Domain\Fulfilment\Jobs\ProcessCourierWebhook;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\CourierWebhookReceipt;
use App\Domain\Fulfilment\Services\CourierRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A courier webhook (P6). In this order, without exception:
 *
 *   1. Resolve the provider from the URL. Unknown → 404, nothing written.
 *   2. VERIFY the signature on the raw bytes through the driver's `verifyWebhook()`.
 *      Invalid → 401, nothing parsed, nothing written (rule 19).
 *   3. Parse. An event that names no consignment (Pathao's `webhook_integration`
 *      handshake, store events, Steadfast's `user_update`) is acknowledged and dropped.
 *   4. Record a `webhook_receipts` row keyed on (courier:{code}, event id). The event id is
 *      the provider's when it sends one, else a hash of the raw body, so a retried
 *      delivery of the same bytes is a no-op.
 *   5. Queue {@see ProcessCourierWebhook} on `courier`: it RE-QUERIES the provider's
 *      `status()` and applies THAT answer — the body is a trigger, never the truth
 *      (rule 24c; Pathao's signature is a plain shared secret).
 *
 * No transaction here: one idempotent insert, then a dispatch.
 */
final readonly class ReceiveCourierWebhook
{
    public function __construct(private CourierRegistry $registry) {}

    /**
     * @param  array<string, list<string|null>|string>  $headers  lower-cased names
     * @param  array<string, string|null>  $keptHeaders  the allowlisted subset worth storing
     * @return array{status: int, headers: array<string, string>, replayed: bool, ignored: bool, receipt_id: int|null}
     */
    public function handle(string $code, array $headers, string $rawBody, array $keptHeaders = []): array
    {
        $driver = $this->driver($code);
        [$status, $ackHeaders] = $driver instanceof ShapesWebhookResponse
            ? [$driver->webhookAckStatus(), $driver->webhookAckHeaders()]
            : [200, []];

        /** @var array<string, list<string>|string> $headers */
        if (! $driver->verifyWebhook($headers, $rawBody)) {
            Log::warning('courier.webhook.signature_invalid', ['provider' => $code, 'bytes' => strlen($rawBody)]);

            throw InvalidCourierWebhookSignature::make($ackHeaders);
        }

        $event = $driver->parseWebhook($rawBody);
        if ($event === null) {
            return ['status' => $status, 'headers' => $ackHeaders, 'replayed' => false, 'ignored' => true, 'receipt_id' => null];
        }

        $eventId = $event->eventId !== null && trim($event->eventId) !== ''
            ? Str::limit(trim($event->eventId), 180, '')
            : 'sha256:'.hash('sha256', $rawBody);
        $provider = CourierWebhookReceipt::providerKey($code);

        $inserted = CourierWebhookReceipt::query()->insertOrIgnore([[
            'provider' => $provider,
            'external_event_id' => $eventId,
            'signature_valid' => true,
            'payload' => json_encode([
                'consignment_id' => Str::limit($event->consignmentId, 64, ''),
                'merchant_order_ref' => $event->merchantOrderRef === null ? null : Str::limit($event->merchantOrderRef, 64, ''),
                'raw_status' => Str::limit($event->rawStatus, 64, ''),
                'body' => self::storable($rawBody),
            ], JSON_THROW_ON_ERROR),
            'headers' => json_encode(array_filter($keptHeaders, static fn (?string $v): bool => $v !== null), JSON_THROW_ON_ERROR),
            'source' => 'webhook',
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        /** @var CourierWebhookReceipt $receipt */
        $receipt = CourierWebhookReceipt::query()->where('provider', $provider)->where('external_event_id', $eventId)->firstOrFail();
        $replayed = $inserted === 0;

        // A replay of a processed event does nothing. One still unprocessed (its job was
        // deferred or lost) is queued again; the job itself skips a processed receipt.
        if ($receipt->processed_at === null) {
            ProcessCourierWebhook::dispatch($receipt->id)->afterCommit();
        }

        return ['status' => $status, 'headers' => $ackHeaders, 'replayed' => $replayed, 'ignored' => false, 'receipt_id' => $receipt->id];
    }

    private function driver(string $code): CourierGateway
    {
        $known = preg_match('/^[a-z0-9_\-]{1,24}$/', $code) === 1
            && $this->registry->supports($code)
            && CourierProvider::query()->where('code', $code)->exists();

        if (! $known) {
            throw new NotFoundHttpException;
        }

        return $this->registry->for($code);
    }

    /**
     * The body as kept for disputes: top-level scalar fields only, bounded, and never a
     * recipient's phone, name or address (rule 20). Unparseable bodies keep nothing.
     *
     * @return array<string, string|int|float|bool|null>
     */
    private static function storable(string $rawBody): array
    {
        $data = json_decode($rawBody, true);
        if (! is_array($data)) {
            return [];
        }

        $kept = [];
        foreach (array_slice($data, 0, 60, true) as $key => $value) {
            $key = Str::limit((string) $key, 64, '');
            if (preg_match('/phone|mobile|address|name|recipient|email/i', $key) === 1) {
                continue;
            }
            if (is_string($value)) {
                $kept[$key] = Str::limit($value, 300, '');
            } elseif (is_scalar($value) || $value === null) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }
}

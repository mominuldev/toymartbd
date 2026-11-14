<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Contracts;

use App\Domain\Inbox\DTOs\InboundMessage;
use App\Domain\Inbox\DTOs\StatusUpdate;
use App\Domain\Inbox\DTOs\WatermarkUpdate;

/**
 * A channel that pushes events to /api/v1/webhooks/inbox/{channel}. Order is fixed:
 * {@see verifySignature()} on the RAW bytes first; only a verified body is ever parsed or
 * written (rule 19).
 */
interface ReceivesWebhooks
{
    /**
     * Meta's subscription handshake (GET ?hub.mode=subscribe&hub.verify_token=…&hub.challenge=…).
     * Returns the challenge to echo, or null to refuse.
     */
    public function verifySubscription(?string $mode, ?string $verifyToken, ?string $challenge): ?string;

    /** @param  array<string, list<string|null>|string|null>  $headers  lower-cased names */
    public function verifySignature(array $headers, string $rawBody): bool;

    /**
     * Only after verifySignature() returned true. Unknown shapes yield no events.
     *
     * @return list<InboundMessage|StatusUpdate|WatermarkUpdate>
     */
    public function parse(string $rawBody): array;
}

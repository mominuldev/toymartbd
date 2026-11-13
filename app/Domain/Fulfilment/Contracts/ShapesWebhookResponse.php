<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Contracts;

/**
 * OPTIONAL capability of a {@see CourierGateway}: the acknowledgement a provider requires.
 * Pathao, for one, fails its panel verification unless every response is a 202 that
 * echoes `X-Pathao-Merchant-Webhook-Integration-Secret` (a global constant, not our
 * secret — docs/_research/agent3-appbyte-and-couriers.md §5.5). Drivers that do not
 * implement this get a plain 200.
 */
interface ShapesWebhookResponse
{
    public function webhookAckStatus(): int;

    /** @return array<string, string> */
    public function webhookAckHeaders(): array;
}

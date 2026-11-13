<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Contracts;

use App\Domain\Fulfilment\DTOs\CourierStatusResult;

/**
 * OPTIONAL capability of a {@see CourierGateway}: look a parcel up by our merchant order
 * ref (the invoice we sent) rather than the provider's consignment id. It is how the
 * reconciliation poll resolves a bulk-`submitted` shipment, which has no consignment id
 * yet (rule 24d). Steadfast documents `GET /status_by_invoice/{invoice}`; Pathao
 * documents no such lookup, so its submitted shipments wait for the webhook.
 *
 * The result MUST carry `consignmentId` when the provider returned one; null means the
 * provider does not know the ref (yet).
 */
interface LooksUpByMerchantRef
{
    public function statusByMerchantRef(string $merchantOrderRef): ?CourierStatusResult;
}

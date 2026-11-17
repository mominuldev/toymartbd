<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Fulfilment\DTOs\ConnectionCheck;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The answer to Settings › Couriers › "Check connection". Never echoes a credential (rule 20).
 *
 * @property ConnectionCheck $resource
 */
#[SchemaName('CourierConnectionCheck')]
final class CourierConnectionCheckResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            /** @var bool The courier accepted the saved credentials. */
            'ok' => $c->ok,
            /** @var 'connected'|'no_credentials'|'credentials_rejected'|'unreachable'|'store_missing'|'store_not_found'|'unsupported' */
            'reason' => $c->reason,
            'checked_at' => $c->checkedAt->toIso8601String(),
            /** @var string|null Steadfast: the balance it reported (can be negative). */
            'balance' => $c->balance?->toDecimal(),
            /** @var string|null Pathao: the pickup store the saved id points at. */
            'store_name' => $c->storeName,
            /** @var bool Pathao: the check ran against Pathao's test server. */
            'sandbox' => $c->sandbox,
        ];
    }
}

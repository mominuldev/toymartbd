<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The answer to Integrations › Payments › "Check connection". Never echoes a key (rule 20).
 *
 * @property array{ok: bool, code: string, mode: string} $resource
 */
#[SchemaName('PaymentGatewayConnectionCheck')]
final class PaymentGatewayConnectionCheckResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var bool The gateway accepted the keys saved for the current mode. */
            'ok' => $this->resource['ok'],
            /** @var 'ok'|'not_configured'|'credentials_rejected'|'unreachable'|'sandbox_only' */
            'code' => $this->resource['code'],
            /** @var 'sandbox'|'live' The mode whose keys were checked. */
            'mode' => $this->resource['mode'],
            'checked_at' => now()->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Store-level delivery policy shown beside the courier rate cards.
 *
 * @property array{free_shipping_threshold: string|null} $resource
 */
#[SchemaName('DeliverySettings')]
final class DeliverySettingsResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string|null Subtotal at or above which delivery is free in every zone; null = off. */
            'free_shipping_threshold' => $this->resource['free_shipping_threshold'],
        ];
    }
}

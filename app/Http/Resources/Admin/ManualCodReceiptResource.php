<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Finance\Models\ManualCodReceipt;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A recorded COD receipt and the order's outstanding receivable after it.
 *
 * @property ManualCodReceipt $resource
 */
#[SchemaName('ManualCodReceipt')]
final class ManualCodReceiptResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'id' => $r->id,
            'order_id' => $r->order_id,
            /** @var string */
            'amount' => $r->amount->toDecimal(),
            /** @var string */
            'received_on' => $r->received_on->toDateString(),
            'reference' => $r->reference,
            'note' => $r->note,
            /** @var string */
            'order_outstanding' => (string) $r->getAttribute('order_outstanding'),
        ];
    }
}

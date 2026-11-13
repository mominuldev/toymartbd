<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Purchasing\Models\SupplierPayment;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A payment to a supplier (finance.view to read).
 *
 * @property SupplierPayment $resource
 */
#[SchemaName('SupplierPayment')]
final class SupplierPaymentResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payment = $this->resource;

        return [
            'id' => $payment->id,
            'supplier_id' => $payment->supplier_id,
            /** @var int|null */
            'purchase_id' => $payment->purchase_id,
            /** @var string|null */
            'purchase_number' => $payment->purchase?->number,
            'amount' => $payment->amount->toDecimal(),
            /** @var 'cash'|'bank_transfer'|'cheque'|'bkash'|'nagad'|'other' */
            'method' => $payment->method->value,
            /** @var string|null */
            'reference' => $payment->reference,
            /**
             * Dhaka date, Y-m-d.
             *
             * @var string
             */
            'paid_at' => $payment->paid_at->toDateString(),
            /** @var string|null */
            'note' => $payment->note,
            /**
             * @var string
             *
             * @format date-time
             */
            'created_at' => ProductResource::utcIso($payment->created_at),
        ];
    }
}

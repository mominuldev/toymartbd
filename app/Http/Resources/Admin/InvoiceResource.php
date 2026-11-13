<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Ordering\Models\Order;
use App\Http\Resources\ApiResource;
use App\Models\User;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One issued invoice's snapshot (P8, N-13): company (`issued_by`), recipient (`issued_to`),
 * lines and totals exactly as issued. A customer's phone is masked and the street line,
 * landmark and postcode omitted without customers.view_pii (rule 20).
 *
 * @property Invoice $resource
 */
#[SchemaName('Invoice')]
final class InvoiceResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $invoice = $this->resource;
        $user = $request->user();
        $pii = $user instanceof User && $user->can('viewPii', Order::class);
        $to = $invoice->issued_to;
        $by = $invoice->issued_by;

        if ($invoice->type === InvoiceType::Sales && ! $pii) {
            $phone = PhoneNumber::tryParse((string) ($to['phone'] ?? ''));
            $to['phone'] = $phone?->masked();
            $to['alt_phone'] = null;
            unset($to['address'], $to['landmark'], $to['postcode']);
        }

        return [
            ...(new InvoiceListItemResource($invoice))->toArray($request),
            /** @var 'standard' */
            'template' => $invoice->template,
            /** @var string */
            'subtotal' => $invoice->subtotal->toDecimal(),
            /** @var string */
            'discount' => $invoice->discount->toDecimal(),
            /** @var string */
            'shipping' => $invoice->shipping->toDecimal(),
            /** @var string */
            'tax' => $invoice->tax->toDecimal(),
            /** @var string|null */
            'amount_in_words' => $invoice->amount_in_words,
            /** @var string|null */
            'due_at' => $invoice->due_at?->toIso8601String(),
            /**
             * The company details as issued.
             *
             * @var array{name_en: string|null, name_bn: string|null, address_en: string|null, address_bn: string|null, hotline: string|null, email: string|null, website: string|null, trade_licence: string|null, bin: string|null, logo_media_id: int|null}
             */
            'issued_by' => [
                'name_en' => $by['name_en'] ?? null,
                'name_bn' => $by['name_bn'] ?? null,
                'address_en' => $by['address_en'] ?? null,
                'address_bn' => $by['address_bn'] ?? null,
                'hotline' => $by['hotline'] ?? null,
                'email' => $by['email'] ?? null,
                'website' => $by['website'] ?? null,
                'trade_licence' => $by['trade_licence'] ?? null,
                'bin' => $by['bin'] ?? null,
                'logo_media_id' => isset($by['logo_media_id']) ? (int) $by['logo_media_id'] : null,
            ],
            /**
             * The customer (ship-to) or supplier as issued; PII masked without customers.view_pii.
             *
             * @var array<string, mixed>
             */
            'issued_to' => $to,
            /** @var list<array{name: string, name_bn: string|null, variant: string|null, sku: string, quantity: int, unit_price: string, discount: string, line_total: string}> */
            'lines' => $invoice->lines,
        ];
    }
}

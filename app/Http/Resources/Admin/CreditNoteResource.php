<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Invoicing\Models\CreditNote;
use App\Domain\Ordering\Models\Order;
use App\Http\Resources\ApiResource;
use App\Models\User;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One issued credit note's snapshot (P8 batch 2): the invoice it credits, company, recipient,
 * credited lines and totals exactly as issued. The customer's phone is masked and the
 * street line, landmark and postcode omitted without customers.view_pii (rule 20).
 *
 * @property CreditNote $resource
 */
#[SchemaName('CreditNote')]
final class CreditNoteResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $note = $this->resource;
        $user = $request->user();
        $to = $note->issued_to;
        $by = $note->issued_by;

        if (! ($user instanceof User && $user->can('viewPii', Order::class))) {
            $phone = PhoneNumber::tryParse((string) ($to['phone'] ?? ''));
            $to['phone'] = $phone?->masked();
            $to['alt_phone'] = null;
            unset($to['address'], $to['landmark'], $to['postcode']);
        }

        return [
            ...(new CreditNoteListItemResource($note))->toArray($request),
            /**
             * When the credited invoice was issued. ISO 8601, UTC.
             *
             * @var string
             */
            'invoice_issued_at' => $note->invoice_issued_at->toIso8601String(),
            /**
             * The refund's reason text (refund credit notes) or the edit's note (order_edited); null otherwise.
             *
             * @var string|null
             */
            'note' => $note->note,
            /** @var 'standard' */
            'template' => $note->template,
            /** @var string */
            'subtotal' => $note->subtotal->toDecimal(),
            /** @var string */
            'discount' => $note->discount->toDecimal(),
            /** @var string */
            'shipping' => $note->shipping->toDecimal(),
            /** @var string */
            'tax' => $note->tax->toDecimal(),
            /**
             * Signed: what the parts above do not explain (e.g. a refund capped below its lines).
             *
             * @var string
             */
            'adjustment' => $note->adjustment->toDecimal(),
            /** @var string|null */
            'amount_in_words' => $note->amount_in_words,
            /** @var string|null */
            'amount_in_words_bn' => $note->amount_in_words_bn,
            /**
             * The company details as at the credit note's issue.
             *
             * @var array{name_en: string|null, name_bn: string|null, address_en: string|null, address_bn: string|null, hotline: string|null, email: string|null, website: string|null, trade_licence: string|null, bin: string|null}
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
            ],
            /**
             * The customer as on the invoice; PII masked without customers.view_pii.
             *
             * @var array<string, mixed>
             */
            'issued_to' => $to,
            /** @var list<array{kind: 'item'|'balance'|'adjustment', name: string, name_bn: string|null, variant: string|null, sku: string|null, quantity: int, unit_price: string, amount: string}> */
            'lines' => $note->lines,
        ];
    }
}

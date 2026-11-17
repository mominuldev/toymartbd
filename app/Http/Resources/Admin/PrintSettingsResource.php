<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Settings › Invoices, receipts & labels: what each printed document shows. Every field always
 * has a value (the Figma default until saved); `saved` lists the documents saved at least once.
 *
 * @property array{invoice: array<string, mixed>, receipt: array<string, mixed>, labels: array<string, mixed>, saved: list<string>} $resource
 */
#[SchemaName('PrintSettings')]
final class PrintSettingsResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $i = $this->resource['invoice'];
        $r = $this->resource['receipt'];
        $l = $this->resource['labels'];

        return [
            /**
             * The A4 customer invoice.
             *
             * @var array{paper: 'a4'|'a5', language: 'en'|'bn'|'both', colour: 'brand'|'mono', show_bin: bool, show_barcode: bool, show_phone: bool, show_email: bool, show_sku: bool, show_options: bool, show_payment_stamp: bool, show_track_qr: bool, show_signatures: bool}
             */
            'invoice' => $i,
            /**
             * The POS counter receipt.
             *
             * @var array{paper: '80'|'58', language: 'en'|'bn'|'ask', text_size: 'normal'|'large', auto_print: bool, show_logo: bool, show_branch: bool, show_bin: bool, show_cashier: bool, show_customer_name: bool, show_sku: bool, show_barcode: bool, show_whatsapp_qr: bool, show_cut_line: bool, footer_en: string|null, footer_bn: string|null}
             */
            'receipt' => $r,
            /**
             * Product barcode labels.
             *
             * @var array{template: 'roll_38x25'|'roll_50x25'|'a4_65'|'a4_24', show_name: bool, show_options: bool, show_price: bool, show_store: bool, show_digits: bool, generate_missing: bool}
             */
            'labels' => $l,
            /**
             * Documents saved at least once; the others print with the defaults.
             *
             * @var list<'invoice'|'receipt'|'labels'>
             */
            'saved' => $this->resource['saved'],
        ];
    }
}

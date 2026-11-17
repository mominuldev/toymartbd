<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Pos;

use App\Domain\Invoicing\Services\PrintSettings;
use App\Domain\Invoicing\Services\ReceiptCodes;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Payment\Models\Payment;
use App\Domain\Pos\Models\PosDueCollectionAllocation;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Services\PosCustomers;
use App\Http\Resources\ApiResource;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A printable counter receipt (browser print). Both languages are returned — the register
 * prints the one the UI is in; digits are Western in both (rule 36). The store block is the
 * owner's Business / Invoice details (CompanyDetails), with `placeholders` naming the required
 * fields still unset. The customer's phone is masked (rule 20). Every figure is the order's.
 *
 * WhatsApp receipt: `whatsapp.enabled` is false with reason `no_provider` until a WhatsApp
 * provider is live.
 *
 * `print` is the owner's Thermal receipt settings (Settings › Invoices, receipts & labels,
 * {@see PrintSettings::receipt()}) — on the receipt itself because a cashier may not hold
 * settings.view. `logo_url`, `barcode_svg` and `whatsapp_qr_svg` are only filled when the
 * setting that prints them is on (and the logo / number exists).
 *
 * @property array{sale: PosSale, company: array<string, mixed>, print: array<string, string|bool|null>, logo_url: string|null, whatsapp_link: string|null} $resource
 */
#[SchemaName('PosReceipt')]
final class PosReceiptResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $sale = $this->resource['sale'];
        $company = $this->resource['company'];
        $order = $sale->order;
        $location = $sale->session->location;
        $customer = $order->customer;
        $walkIn = PosCustomers::isWalkIn($customer);
        $phone = $walkIn ? null : PhoneNumber::tryParse($order->shipping_phone);
        // The sale's own tenders only: due collected later on this order is its own record.
        $collected = PosDueCollectionAllocation::query()->where('order_id', $order->id)->pluck('payment_id')->all();
        $text = static fn (string $key): ?string => is_string($company[$key] ?? null) ? $company[$key] : null;
        $print = $this->resource['print'];
        $codes = app(ReceiptCodes::class);
        $whatsappLink = $print['show_whatsapp_qr'] === true ? $this->resource['whatsapp_link'] : null;

        return [
            'store' => [
                'name_en' => $text('name_en') ?? '',
                'name_bn' => $text('name_bn') ?? '',
                /** @var string|null */
                'address_en' => $text('address_en'),
                /** @var string|null */
                'address_bn' => $text('address_bn'),
                /** @var string|null */
                'hotline' => $text('hotline'),
                /** @var string|null */
                'bin' => $text('bin'),
                /** @var string|null */
                'trade_licence' => $text('trade_licence'),
                /** @var string|null */
                'footer_en' => $text('footer_en'),
                /** @var string|null */
                'footer_bn' => $text('footer_bn'),
                /**
                 * Required business fields the owner has not filled in yet.
                 *
                 * @var list<string>
                 */
                'placeholders' => array_values(array_filter((array) ($company['placeholders'] ?? []), 'is_string')),
            ],
            'branch' => [
                'name' => $location->name,
                /** @var string|null */
                'name_bn' => $location->name_bn,
                /** @var string|null */
                'address' => $location->address_line,
            ],
            'register' => $sale->session->register,
            'cashier' => $sale->cashier->name,
            'order_number' => $order->number,
            /** ISO 8601 instant; print it in Dhaka time. */
            'sold_at' => ($order->delivered_at ?? $sale->created_at ?? now())->toIso8601String(),
            'customer' => [
                /** @var string|null */
                'name' => $walkIn ? null : $customer->name,
                /** 017****5678. @var string|null */
                'phone_masked' => $phone?->masked(),
            ],
            /** @var list<array{name: string, name_bn: string|null, variant_name: string|null, sku: string, quantity: int, unit_price: string, discount: string, total: string}> */
            'lines' => $order->items->map(static fn (OrderItem $item): array => [
                'name' => $item->product_name,
                'name_bn' => $item->product_name_bn,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price->toDecimal(),
                'discount' => $item->discount->toDecimal(),
                'total' => $item->line_total->toDecimal(),
            ])->values()->all(),
            'subtotal' => $order->subtotal->toDecimal(),
            'promotion_discount' => $order->promotion_discount->toDecimal(),
            'coupon_discount' => $order->coupon_discount->toDecimal(),
            /** @var string|null */
            'coupon_code' => $order->coupon_code,
            /** The cashier's discount ("0.00" when none). */
            'manual_discount' => $order->manual_discount->toDecimal(),
            /**
             * The reason code (`regular_customer`, `bulk_purchase`, … — the register shows its label).
             *
             * @var 'regular_customer'|'bulk_purchase'|'damaged_packaging'|'price_match'|'staff_purchase'|'promotional'|'other'|null
             */
            'discount_reason' => $sale->discount_reason,
            'grand_total' => $order->grand_total->toDecimal(),
            /** @var list<array{method: string, amount: string, reference: string|null}> */
            'payments' => $order->payments->whereNotIn('id', $collected)->sortBy('id')->map(static fn (Payment $p): array => [
                'method' => $p->method->value,
                'amount' => $p->amount->toDecimal(),
                'reference' => $p->gateway_reference,
            ])->values()->all(),
            'cash_tendered' => $sale->cash_tendered->toDecimal(),
            'change_due' => $sale->change_due->toDecimal(),
            /** Put on the customer's due at this sale (not a payment — `payments` lists money only). */
            'due_amount' => $sale->due_amount->toDecimal(),
            'whatsapp' => [
                'enabled' => false,
                /** @var 'no_provider' */
                'reason' => 'no_provider',
            ],
            /**
             * How the receipt prints (Settings › Invoices, receipts & labels › Thermal receipt).
             * `language` `ask` = the cashier picks per sale; `en`/`bn` fixes it.
             *
             * @var array{paper: '80'|'58', language: 'en'|'bn'|'ask', text_size: 'normal'|'large', auto_print: bool, show_logo: bool, show_branch: bool, show_bin: bool, show_cashier: bool, show_customer_name: bool, show_sku: bool, show_barcode: bool, show_whatsapp_qr: bool, show_cut_line: bool, footer_en: string|null, footer_bn: string|null}
             */
            'print' => $print,
            /**
             * The store logo (printed in black); null when none is chosen or the logo is switched off.
             *
             * @var string|null
             */
            'logo_url' => $print['show_logo'] === true ? $this->resource['logo_url'] : null,
            /**
             * Code 128 of the order number as a standalone SVG; null when the barcode is switched off.
             *
             * @var string|null
             */
            'barcode_svg' => $print['show_barcode'] === true ? $codes->code128Svg($order->number) : null,
            /**
             * The store's WhatsApp chat link (`https://wa.me/…`); null when switched off or no number is saved.
             *
             * @var string|null
             */
            'whatsapp_link' => $whatsappLink,
            /**
             * A QR code of `whatsapp_link` as a standalone SVG, or null.
             *
             * @var string|null
             */
            'whatsapp_qr_svg' => $whatsappLink !== null ? $codes->qrSvg($whatsappLink) : null,
        ];
    }
}

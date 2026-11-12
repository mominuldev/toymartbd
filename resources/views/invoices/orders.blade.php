{{--
    Invoice + packing slip (P4-ORD-08), rendered by mPDF (OrderInvoicePdf) — one page per
    order. Bangla shapes correctly only through mPDF's OpenType layout with the embedded
    Hind Siliguri font (resources/fonts/invoice, SIL OFL); dompdf drops the conjuncts and
    vowel reordering, so it is not used here.

    PDF CSS cannot read the admin's CSS custom properties, so the Theme B token VALUES are
    mirrored below with their token names (packages/tokens). Numerals are Western in both
    languages (rule 36). Business identity lines are placeholders (UI-UX-DESIGN-SPEC §10).

    @var list<array{order: \App\Domain\Ordering\Models\Order, locale: string}> $invoices
--}}
@php
    $t = [
        'text' => '#0F172A',      // color/text/primary
        'muted' => '#475569',     // color/text/secondary
        'border' => '#E2E6EC',    // color/border/default
        'subtle' => '#EEF1F5',    // color/bg/subtle
        'brand' => '#4F46E5',     // color/bg/brand
    ];
    $money = static fn (\App\Support\Money $m): string => \App\Support\Locale\Formatter::money($m);
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: hindsiliguri; color: {{ $t['text'] }}; font-size: 10pt; line-height: 1.45; }
    h1 { font-size: 16pt; margin: 0; color: {{ $t['brand'] }}; }
    .muted { color: {{ $t['muted'] }}; font-size: 8.5pt; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .box { border: 0.6pt solid {{ $t['border'] }}; padding: 8pt; }
    .items th { background: {{ $t['subtle'] }}; text-align: left; font-weight: bold; padding: 5pt; font-size: 9pt; border-bottom: 0.6pt solid {{ $t['border'] }}; }
    .items td { padding: 5pt; border-bottom: 0.6pt solid {{ $t['border'] }}; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .totals td { padding: 3pt 5pt; }
    .grand td { font-weight: bold; font-size: 12pt; border-top: 0.8pt solid {{ $t['text'] }}; }
    .due { background: {{ $t['subtle'] }}; font-weight: bold; }
</style>
</head>
<body>
@foreach ($invoices as $i => $invoice)
    @php
        /** @var \App\Domain\Ordering\Models\Order $order */
        $order = $invoice['order'];
        $locale = $invoice['locale'];
        $tr = static fn (string $key): string => (string) __('invoices.'.$key, [], $locale);
        $items = $order->items->filter(static fn ($item): bool => $item->quantity > 0);
    @endphp
    @if ($i > 0)
        <pagebreak />
    @endif
    <div class="lang-{{ $locale }}" lang="{{ $locale }}">
    <table class="head">
        <tr>
            <td style="width: 55%">
                <h1>{{ $tr('shop') }}</h1>
                <div class="muted">{{ $tr('shop_line') }}</div>
                <div class="muted">{{ $tr('hotline') }}</div>
                <div class="muted">{{ $tr('licence') }}</div>
            </td>
            <td style="width: 45%; text-align: right">
                <div style="font-size: 14pt; font-weight: bold">{{ $tr('title') }}</div>
                <div>{{ $tr('number') }}: <b>{{ $order->number }}</b></div>
                <div class="muted">{{ $tr('placed') }}: {{ $order->placed_at ? \App\Support\Locale\Formatter::dateTime($order->placed_at, $locale) : '' }}</div>
            </td>
        </tr>
    </table>

    <div class="box" style="margin-top: 10pt">
        <div class="muted">{{ $tr('ship_to') }}</div>
        <div style="font-weight: bold; font-size: 11pt">{{ $order->shipping_name }}</div>
        <div>{{ $tr('phone') }}: {{ \App\Support\PhoneNumber::tryParse($order->shipping_phone)?->national() ?? $order->shipping_phone }}@if ($order->shipping_alt_phone) · {{ \App\Support\PhoneNumber::tryParse($order->shipping_alt_phone)?->national() ?? $order->shipping_alt_phone }}@endif</div>
        <div>{{ $order->shipping_address }}</div>
        <div>{{ collect([$order->shipping_area, $order->shipping_upazila, $order->shipping_district, $order->shipping_division])->filter()->implode(', ') }}</div>
        @if ($order->shipping_landmark)
            <div>{{ $tr('landmark') }}: {{ $order->shipping_landmark }}</div>
        @endif
    </div>

    <table class="items" style="margin-top: 10pt">
        <thead>
            <tr>
                <th style="width: 50%">{{ $tr('item') }}</th>
                <th>{{ $tr('sku') }}</th>
                <th class="num">{{ $tr('qty') }}</th>
                <th class="num">{{ $tr('unit_price') }}</th>
                <th class="num">{{ $tr('total') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($items as $item)
            <tr>
                <td>
                    {{ $locale === 'bn' && $item->product_name_bn ? $item->product_name_bn : $item->product_name }}
                    @if ($item->variant_name)
                        <div class="muted">{{ $item->variant_name }}</div>
                    @endif
                </td>
                <td class="muted">{{ $item->sku }}</td>
                <td class="num">{{ $item->quantity }}</td>
                <td class="num">{{ $money($item->unit_price) }}</td>
                <td class="num">{{ $money($item->line_total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top: 8pt">
        <tr><td style="width: 60%"></td><td>{{ $tr('subtotal') }}</td><td class="num">{{ $money($order->subtotal) }}</td></tr>
        @if (! $order->discount_total->isZero())
            <tr><td></td><td>{{ $tr('discount') }}@if ($order->coupon_code) ({{ $order->coupon_code }})@endif</td><td class="num">{{ $money($order->discount_total->negate()) }}</td></tr>
        @endif
        <tr><td></td><td>{{ $tr('delivery') }}</td><td class="num">{{ $money($order->shipping_charge) }}</td></tr>
        <tr class="grand"><td></td><td>{{ $tr('grand_total') }}</td><td class="num">{{ $money($order->grand_total) }}</td></tr>
        @if (! $order->paid_total->isZero())
            <tr><td></td><td>{{ $tr('paid') }}</td><td class="num">{{ $money($order->paid_total) }}</td></tr>
        @endif
        <tr class="due"><td></td><td>{{ $tr('due') }}</td><td class="num">{{ $money($order->due_total) }}</td></tr>
    </table>

    <div style="margin-top: 8pt">{{ $tr('payment') }}: {{ $order->payment_method === 'cod' ? $tr('cod') : $order->payment_method }}</div>
    @if ($order->customer_note)
        <div class="box" style="margin-top: 8pt"><span class="muted">{{ $tr('note') }}:</span> {{ $order->customer_note }}</div>
    @endif

    <p class="muted" style="margin-top: 16pt; text-align: center">{{ $tr('thanks') }}</p>
    </div>
@endforeach
</body>
</html>

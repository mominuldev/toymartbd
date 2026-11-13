{{--
    Customer invoice + packing slip, and supplier (purchase) invoice (P4-ORD-08, P8 N-13),
    rendered by mPDF (OrderInvoicePdf) — one page per invoice. EVERYTHING printed comes
    from the issued invoice's snapshot (`issued_by`, `issued_to`, `lines`, totals): a
    reprint shows the company details, address and figures current when it was issued,
    never today's. Nothing is computed here.

    Bangla shapes correctly only through mPDF's OpenType layout with the embedded Hind
    Siliguri font (resources/fonts/invoice, SIL OFL); dompdf drops the conjuncts and vowel
    reordering, so it is not used here.

    PDF CSS cannot read the admin's CSS custom properties, so the Theme B token VALUES are
    mirrored below with their token names (packages/tokens). Numerals are Western in both
    languages (rule 36). A required company field the owner has not set prints
    "[placeholder]" (UI-UX-DESIGN-SPEC §10). The layout keeps a free band under the header
    for a VAT/Mushak block (PRD N-14, P2 — needs an accountant first; not built).

    @var list<array{invoice: \App\Domain\Invoicing\Models\Invoice, locale: string}> $invoices
--}}
@php
    $t = [
        'text' => '#0F172A',      // color/text/primary
        'muted' => '#475569',     // color/text/secondary
        'border' => '#E2E6EC',    // color/border/default
        'subtle' => '#EEF1F5',    // color/bg/subtle
        'brand' => '#4F46E5',     // color/bg/brand
    ];
    $money = static fn (\App\Support\Money|string $m): string => \App\Support\Locale\Formatter::money($m);
    // Purchase unit costs carry up to 4 decimals (DECIMAL(14,4)); show them when non-zero.
    $cost = static function (string $v): string {
        $fraction = rtrim(explode('.', $v.'.')[1], '0');

        return strlen($fraction) > 2
            ? '৳'.\App\Support\Locale\Formatter::number($v, 4)
            : \App\Support\Locale\Formatter::money(bcadd($v, '0', 2));
    };
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: hindsiliguri; color: {{ $t['text'] }}; font-size: 10pt; line-height: 1.45; }
    .lang-bn { line-height: 1.65; }
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
@foreach ($invoices as $i => $entry)
    @php
        /** @var \App\Domain\Invoicing\Models\Invoice $invoice */
        $invoice = $entry['invoice'];
        $locale = $entry['locale'];
        $bn = $locale === 'bn';
        $sales = $invoice->type === \App\Domain\Invoicing\Enums\InvoiceType::Sales;
        // P8 batch 3: the increase of an order edited upwards after its invoice was issued.
        $supplementary = $invoice->isSupplementary();
        $tr = static fn (string $key, array $replace = []): string => (string) __('invoices.'.$key, $replace, $locale);
        $by = $invoice->issued_by;
        $to = $invoice->issued_to;
        $missing = (array) ($by['placeholders'] ?? []);
        $field = static fn (string $key): string => in_array($key, $missing, true) || ($by[$key] ?? null) === null ? $tr('placeholder') : (string) $by[$key];
        $phone = static fn (?string $p): string => $p === null ? '' : (\App\Support\PhoneNumber::tryParse($p)?->national() ?? $p);
        $date = static fn (?string $iso): string => $iso === null ? '' : \App\Support\Locale\Formatter::date(\Carbon\CarbonImmutable::parse($iso), $locale);
    @endphp
    @if ($i > 0)
        <pagebreak />
    @endif
    <div class="lang-{{ $locale }}" lang="{{ $locale }}">
    <table class="head">
        <tr>
            <td style="width: 55%">
                <h1>{{ $bn ? ($by['name_bn'] ?? $by['name_en'] ?? '') : ($by['name_en'] ?? '') }}</h1>
                <div class="muted">{{ $bn ? $field('address_bn') : $field('address_en') }}</div>
                <div class="muted">{{ $tr('hotline_label') }}: {{ $field('hotline') }}@if (! empty($by['email'])) · {{ $by['email'] }}@endif @if (! empty($by['website'])) · {{ $by['website'] }}@endif</div>
                <div class="muted">{{ $tr('trade_licence') }}: {{ $field('trade_licence') }} · {{ $tr('bin') }}: {{ $field('bin') }}</div>
            </td>
            <td style="width: 45%; text-align: right">
                <div style="font-size: 14pt; font-weight: bold">{{ $supplementary ? $tr('supplementary.title') : ($sales ? $tr('title') : $tr('purchase_title')) }}</div>
                <div>{{ $tr('number') }}: <b>{{ $invoice->number }}</b></div>
                @if ($supplementary)
                    <div>{{ $tr('supplementary.original') }}: <b>{{ $to['original_invoice_number'] ?? '' }}</b></div>
                    @if (! empty($to['original_issued_at']))
                        <div class="muted">{{ $tr('credit_note.invoice_date') }}: {{ $date($to['original_issued_at']) }}</div>
                    @endif
                @endif
                <div>{{ $sales ? $tr('order') : $tr('purchase_ref') }}: {{ $invoice->reference }}</div>
                <div class="muted">{{ $tr('issued') }}: {{ \App\Support\Locale\Formatter::date($invoice->issued_at, $locale) }}</div>
                @if ($sales && ! empty($to['placed_at']))
                    <div class="muted">{{ $tr('placed') }}: {{ \App\Support\Locale\Formatter::dateTime(\Carbon\CarbonImmutable::parse($to['placed_at']), $locale) }}</div>
                @endif
                @if (! $sales)
                    <div class="muted">{{ $tr('order_date') }}: {{ $date($to['order_date'] ?? null) }}@if (! empty($to['expected_date'])) · {{ $tr('expected_date') }}: {{ $date($to['expected_date']) }}@endif</div>
                @endif
                @if ($invoice->due_at)
                    <div class="muted">{{ $tr('due_date') }}: {{ \App\Support\Locale\Formatter::date($invoice->due_at, $locale) }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="box" style="margin-top: 10pt">
        @if ($sales)
            <div class="muted">{{ $tr('ship_to') }}</div>
            <div style="font-weight: bold; font-size: 11pt">{{ $to['name'] ?? '' }}</div>
            <div>{{ $tr('phone') }}: {{ $phone($to['phone'] ?? null) }}@if (! empty($to['alt_phone'])) · {{ $phone($to['alt_phone']) }}@endif</div>
            <div>{{ $to['address'] ?? '' }}</div>
            <div>{{ collect([$to['area'] ?? null, $to['upazila'] ?? null, $to['district'] ?? null, $to['division'] ?? null])->filter()->implode(', ') }}</div>
            @if (! empty($to['landmark']))
                <div>{{ $tr('landmark') }}: {{ $to['landmark'] }}</div>
            @endif
        @else
            <div class="muted">{{ $tr('supplier') }}</div>
            <div style="font-weight: bold; font-size: 11pt">{{ $to['name'] ?? '' }}</div>
            @if (! empty($to['contact_person']) || ! empty($to['phone']))
                <div>{{ $tr('contact') }}: {{ collect([$to['contact_person'] ?? null, $phone($to['phone'] ?? null) ?: null, $to['email'] ?? null])->filter()->implode(' · ') }}</div>
            @endif
            @if (! empty($to['address']))
                <div>{{ $to['address'] }}</div>
            @endif
            @if (! empty($to['tin']) || ! empty($to['supplier_invoice_no']))
                <div class="muted">{{ collect([! empty($to['tin']) ? $tr('tin').': '.$to['tin'] : null, ! empty($to['supplier_invoice_no']) ? $tr('supplier_invoice_no').': '.$to['supplier_invoice_no'] : null])->filter()->implode(' · ') }}</div>
            @endif
        @endif
    </div>

    <table class="items" style="margin-top: 10pt">
        <thead>
            <tr>
                <th style="width: 50%">{{ $tr('item') }}</th>
                <th>{{ $tr('sku') }}</th>
                <th class="num">{{ $tr('qty') }}</th>
                <th class="num">{{ $sales ? $tr('unit_price') : $tr('unit_cost') }}</th>
                <th class="num">{{ $tr('total') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($invoice->lines as $line)
            <tr>
                @php $adjustment = ($line['kind'] ?? 'item') === 'adjustment'; @endphp
                <td>
                    {{ $bn && ! empty($line['name_bn']) ? $line['name_bn'] : $line['name'] }}
                    @if (! empty($line['variant']))
                        <div class="muted">{{ $line['variant'] }}</div>
                    @endif
                    @if ($adjustment)
                        <div class="muted">{{ $tr('supplementary.adjustment_line') }}</div>
                    @endif
                </td>
                <td class="muted">{{ $line['sku'] }}</td>
                <td class="num">{{ $adjustment ? '' : \App\Support\Locale\Formatter::quantity((int) $line['quantity']) }}</td>
                <td class="num">{{ $adjustment ? '' : ($sales ? $money((string) $line['unit_price']) : $cost((string) $line['unit_price'])) }}</td>
                <td class="num">{{ $money((string) $line['line_total']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top: 8pt">
        <tr><td style="width: 60%"></td><td>{{ $tr('subtotal') }}</td><td class="num">{{ $money($invoice->subtotal) }}</td></tr>
        @if (! $invoice->discount->isZero())
            <tr><td></td><td>{{ $tr('discount') }}@if ($sales && ! empty($to['coupon_code'])) ({{ $to['coupon_code'] }})@endif</td><td class="num">{{ $money($invoice->discount->negate()) }}</td></tr>
        @endif
        @if ($sales)
            <tr><td></td><td>{{ $tr('delivery') }}</td><td class="num">{{ $money($invoice->shipping) }}</td></tr>
        @else
            @foreach (['shipping_cost', 'customs_duty', 'other_cost'] as $part)
                @if (($to['costs'][$part] ?? '0.00') !== '0.00')
                    <tr><td></td><td>{{ $tr($part) }}</td><td class="num">{{ $money((string) $to['costs'][$part]) }}</td></tr>
                @endif
            @endforeach
        @endif
        @if (! $invoice->tax->isZero())
            <tr><td></td><td>{{ $tr('tax') }}</td><td class="num">{{ $money($invoice->tax) }}</td></tr>
        @endif
        <tr class="grand"><td></td><td>{{ $supplementary ? $tr('supplementary.total') : ($sales ? $tr('grand_total') : $tr('po_total')) }}</td><td class="num">{{ $money($invoice->total) }}</td></tr>
        @if (! $invoice->paid->isZero())
            <tr><td></td><td>{{ $tr('paid') }}</td><td class="num">{{ $money($invoice->paid) }}</td></tr>
        @endif
        @if ($sales)
            <tr class="due"><td></td><td>{{ $supplementary ? $tr('supplementary.due') : $tr('due') }}</td><td class="num">{{ $money($invoice->due) }}</td></tr>
        @endif
    </table>

    @if (! $bn && $invoice->amount_in_words)
        <div class="muted" style="margin-top: 6pt">{{ $tr('in_words') }}: {{ $invoice->amount_in_words }}</div>
    @endif
    @if ($supplementary)
        <div class="muted" style="margin-top: 6pt">{{ $tr('supplementary.explain', ['invoice' => $to['original_invoice_number'] ?? '']) }}</div>
    @endif
    @if ($sales)
        <div style="margin-top: 8pt">{{ $tr('payment') }}: {{ $invoice->payment_method === 'cod' ? $tr('cod') : $invoice->payment_method }}</div>
    @endif
    @if (! empty($to['note']))
        <div class="box" style="margin-top: 8pt"><span class="muted">{{ $tr('note') }}:</span> {{ $to['note'] }}</div>
    @endif

    @php $footer = $bn ? ($by['footer_bn'] ?? null) : ($by['footer_en'] ?? null); @endphp
    <p class="muted" style="margin-top: 16pt; text-align: center">{{ $footer ?: ($sales ? $tr('thanks') : '') }}</p>
    <p class="muted" style="text-align: center">{{ $tr('issued_copy', ['date' => \App\Support\Locale\Formatter::date($invoice->issued_at, $locale)]) }}</p>
    </div>
@endforeach
</body>
</html>

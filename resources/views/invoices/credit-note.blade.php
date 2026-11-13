{{--
    Credit note (P8 batch 2), rendered by mPDF (OrderInvoicePdf::renderCreditNotes) — one
    page per credit note, alongside invoice.blade.php and with the same layout, font and
    token values. EVERYTHING printed comes from the issued credit note's snapshot
    (`issued_by`, `issued_to`, `lines`, totals, the original invoice's number and date);
    nothing is computed here, and a reprint shows the details current at issue.

    Bangla shapes only through mPDF's OpenType layout with the embedded Hind Siliguri font
    (see invoice.blade.php). Numerals are Western in both languages (rule 36). A required
    company field the owner has not set prints "[placeholder]".

    @var list<array{credit_note: \App\Domain\Invoicing\Models\CreditNote, locale: string}> $notes
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
</style>
</head>
<body>
@foreach ($notes as $i => $entry)
    @php
        /** @var \App\Domain\Invoicing\Models\CreditNote $note */
        $note = $entry['credit_note'];
        $locale = $entry['locale'];
        $bn = $locale === 'bn';
        $tr = static fn (string $key, array $replace = []): string => (string) __('invoices.'.$key, $replace, $locale);
        $by = $note->issued_by;
        // P9: the logo file referenced at issue, as a local path (never a URL), or null — no image.
        $logo = \App\Domain\Invoicing\Services\InvoiceLogo::localPath($by);
        $to = $note->issued_to;
        $missing = (array) ($by['placeholders'] ?? []);
        $field = static fn (string $key): string => in_array($key, $missing, true) || ($by[$key] ?? null) === null ? $tr('placeholder') : (string) $by[$key];
        $phone = static fn (?string $p): string => $p === null ? '' : (\App\Support\PhoneNumber::tryParse($p)?->national() ?? $p);
    @endphp
    @if ($i > 0)
        <pagebreak />
    @endif
    <div class="lang-{{ $locale }}" lang="{{ $locale }}">
    <table class="head">
        <tr>
            <td style="width: 55%">
                @if ($logo)
                    <img src="{{ $logo }}" alt="" style="height: 14mm; margin-bottom: 4pt" />
                @endif
                <h1>{{ $bn ? ($by['name_bn'] ?? $by['name_en'] ?? '') : ($by['name_en'] ?? '') }}</h1>
                <div class="muted">{{ $bn ? $field('address_bn') : $field('address_en') }}</div>
                <div class="muted">{{ $tr('hotline_label') }}: {{ $field('hotline') }}@if (! empty($by['email'])) · {{ $by['email'] }}@endif @if (! empty($by['website'])) · {{ $by['website'] }}@endif</div>
                <div class="muted">{{ $tr('trade_licence') }}: {{ $field('trade_licence') }} · {{ $tr('bin') }}: {{ $field('bin') }}</div>
            </td>
            <td style="width: 45%; text-align: right">
                <div style="font-size: 14pt; font-weight: bold">{{ $tr('credit_note.title') }}</div>
                <div>{{ $tr('credit_note.number') }}: <b>{{ $note->number }}</b></div>
                <div class="muted">{{ $tr('issued') }}: {{ \App\Support\Locale\Formatter::date($note->issued_at, $locale) }}</div>
                <div>{{ $tr('credit_note.invoice') }}: <b>{{ $note->invoice_number }}</b></div>
                <div class="muted">{{ $tr('credit_note.invoice_date') }}: {{ \App\Support\Locale\Formatter::date($note->invoice_issued_at, $locale) }}</div>
                @if ($note->source_reference)
                    <div class="muted">{{ $tr('credit_note.source') }}: {{ $note->source_reference }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="box" style="margin-top: 10pt">
        <div class="muted">{{ $tr('credit_note.credited_to') }}</div>
        <div style="font-weight: bold; font-size: 11pt">{{ $to['name'] ?? '' }}</div>
        <div>{{ $tr('phone') }}: {{ $phone($to['phone'] ?? null) }}</div>
        <div>{{ $to['address'] ?? '' }}</div>
        <div>{{ collect([$to['area'] ?? null, $to['upazila'] ?? null, $to['district'] ?? null, $to['division'] ?? null])->filter()->implode(', ') }}</div>
    </div>

    <div style="margin-top: 8pt">{{ $tr('credit_note.reason') }}: {{ $tr('credit_note.reason_'.$note->reason->value) }}</div>

    <table class="items" style="margin-top: 10pt">
        <thead>
            <tr>
                <th style="width: 50%">{{ $tr('item') }}</th>
                <th>{{ $tr('sku') }}</th>
                <th class="num">{{ $tr('qty') }}</th>
                <th class="num">{{ $tr('unit_price') }}</th>
                <th class="num">{{ $tr('credit_note.amount') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($note->lines as $line)
            <tr>
                <td>
                    @if (($line['kind'] ?? 'item') === 'balance')
                        {{ $tr('credit_note.balance_line', ['number' => $line['name']]) }}
                    @else
                        {{ $bn && ! empty($line['name_bn']) ? $line['name_bn'] : $line['name'] }}
                        @if (! empty($line['variant']))
                            <div class="muted">{{ $line['variant'] }}</div>
                        @endif
                        @if (($line['kind'] ?? 'item') === 'adjustment')
                            <div class="muted">{{ $tr('supplementary.adjustment_line') }}</div>
                        @endif
                    @endif
                </td>
                @php $adjustment = ($line['kind'] ?? 'item') === 'adjustment'; @endphp
                <td class="muted">{{ $line['sku'] ?? '' }}</td>
                <td class="num">{{ $adjustment ? '' : \App\Support\Locale\Formatter::quantity((int) $line['quantity']) }}</td>
                <td class="num">{{ $adjustment ? '' : $money((string) $line['unit_price']) }}</td>
                <td class="num">{{ $money((string) $line['amount']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top: 8pt">
        <tr><td style="width: 60%"></td><td>{{ $tr('subtotal') }}</td><td class="num">{{ $money($note->subtotal) }}</td></tr>
        @if (! $note->discount->isZero())
            <tr><td></td><td>{{ $tr('discount') }}</td><td class="num">{{ $money($note->discount->negate()) }}</td></tr>
        @endif
        @if (! $note->shipping->isZero())
            <tr><td></td><td>{{ $tr('delivery') }}</td><td class="num">{{ $money($note->shipping) }}</td></tr>
        @endif
        @if (! $note->tax->isZero())
            <tr><td></td><td>{{ $tr('tax') }}</td><td class="num">{{ $money($note->tax) }}</td></tr>
        @endif
        @if (! $note->adjustment->isZero())
            <tr><td></td><td>{{ $tr('credit_note.adjustment') }}</td><td class="num">{{ $money($note->adjustment) }}</td></tr>
        @endif
        <tr class="grand"><td></td><td>{{ $tr('credit_note.total') }}</td><td class="num">{{ $money($note->total) }}</td></tr>
    </table>

    @php
        // P9: Bangla words on documents issued from then on; an older one has none (prints no line).
        $words = $bn ? $note->amount_in_words_bn : $note->amount_in_words;
    @endphp
    @if ($words)
        <div class="muted" style="margin-top: 6pt">{{ $tr('in_words') }}: {{ $words }}</div>
    @endif
    @if ($note->note)
        <div class="box" style="margin-top: 8pt"><span class="muted">{{ $tr('note') }}:</span> {{ $note->note }}</div>
    @endif

    <p class="muted" style="margin-top: 16pt; text-align: center">{{ $tr('credit_note.issued_copy', ['date' => \App\Support\Locale\Formatter::date($note->issued_at, $locale), 'invoice' => $note->invoice_number]) }}</p>
    </div>
@endforeach
</body>
</html>

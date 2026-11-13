{{--
    Shipping label (P6), rendered by mPDF (ShipmentLabelPdf) — one 100 × 150 mm page per
    shipment. Hind Siliguri with OpenType layout so a Bangla address keeps its conjuncts.
    Theme B token VALUES mirrored with their token names (PDF CSS cannot read custom
    properties). Numerals are Western in both languages (rule 36). The full phone and
    address are printed because the courier needs them; the endpoint requires
    customers.view_pii (rule 20). Sender lines are placeholders (UI-UX-DESIGN-SPEC §10).

    @var list<array{shipment: \App\Domain\Fulfilment\Models\Shipment, order: \App\Domain\Ordering\Models\Order}> $labels
    @var string $locale
--}}
@php
    $t = [
        'text' => '#0F172A',      // color/text/primary
        'muted' => '#475569',     // color/text/secondary
        'border' => '#0F172A',    // labels print black hairlines (thermal)
        'subtle' => '#EEF1F5',    // color/bg/subtle
    ];
    $tr = static fn (string $key, array $replace = []): string => (string) __('labels.'.$key, $replace, $locale);
    $money = static fn (\App\Support\Money $m): string => \App\Support\Locale\Formatter::money($m, $locale);
    $phone = static fn (?string $p): string => $p === null ? '' : (\App\Support\PhoneNumber::tryParse($p)?->national() ?? $p);
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: hindsiliguri; color: {{ $t['text'] }}; font-size: 9pt; line-height: 1.35; }
    table { width: 100%; border-collapse: collapse; }
    .cell { border: 0.8pt solid {{ $t['border'] }}; padding: 2.5mm; vertical-align: top; }
    .cap { color: {{ $t['muted'] }}; font-size: 7.5pt; }
    .big { font-size: 13pt; font-weight: bold; }
    .mono { font-family: dejavusansmono; font-size: 11pt; font-weight: bold; }
    .cod { background: {{ $t['subtle'] }}; font-size: 16pt; font-weight: bold; text-align: center; }
    .center { text-align: center; }
</style>
</head>
<body>
@foreach ($labels as $i => $label)
    @php
        $shipment = $label['shipment'];
        $order = $label['order'];
        $place = collect([$order->shipping_area, $order->shipping_upazila, $order->shipping_district])->filter()->implode(', ');
    @endphp
    @if ($i > 0)
        <pagebreak />
    @endif
    <div lang="{{ $locale }}">
    <table>
        <tr>
            <td class="cell" style="width: 60%">
                <div class="cap">{{ $tr('from') }}</div>
                <div style="font-weight: bold">{{ $tr('shop') }}</div>
                <div class="cap">{{ $tr('hotline') }}</div>
            </td>
            <td class="cell" style="width: 40%; text-align: right">
                <div class="big">{{ $shipment->provider->name }}</div>
                <div class="cap">{{ $tr('booked') }}: {{ $shipment->booked_at ? \App\Support\Locale\Formatter::date($shipment->booked_at, $locale) : '' }}</div>
            </td>
        </tr>
        <tr>
            <td class="cell center" colspan="2">
                <div class="cap">{{ $tr('consignment') }}</div>
                <div><barcode code="{{ $shipment->consignment_id }}" type="C128B" size="0.9" height="1.4" /></div>
                <div class="mono">{{ $shipment->consignment_id }}</div>
                @if ($shipment->tracking_code)
                    <div class="cap">{{ $tr('tracking') }}: <span class="mono" style="font-size: 9pt">{{ $shipment->tracking_code }}</span></div>
                @endif
            </td>
        </tr>
        <tr>
            <td class="cell" colspan="2">
                <div class="cap">{{ $tr('to') }}</div>
                <div class="big">{{ $order->shipping_name }}</div>
                <div style="font-size: 11pt; font-weight: bold">{{ $tr('phone') }}: {{ $phone($order->shipping_phone) }}@if ($order->shipping_alt_phone) · {{ $phone($order->shipping_alt_phone) }}@endif</div>
                <div>{{ $order->shipping_address }}</div>
                @if ($place !== '')
                    <div>{{ $place }}</div>
                @endif
                @if ($order->shipping_landmark)
                    <div><b>{{ $tr('landmark') }}:</b> {{ $order->shipping_landmark }}</div>
                @endif
            </td>
        </tr>
        <tr>
            <td class="cell cod" colspan="2">
                @if ($shipment->cod_amount->isZero())
                    <span style="font-size: 12pt">{{ $tr('prepaid') }}</span>
                @else
                    <div class="cap" style="font-weight: normal">{{ $tr('collect') }}</div>
                    {{ $money($shipment->cod_amount) }}
                @endif
            </td>
        </tr>
        <tr>
            <td class="cell">
                <div class="cap">{{ $tr('order') }} · {{ $tr('shipment') }}</div>
                <div class="mono" style="font-size: 9pt">{{ $order->number }}</div>
                <div class="mono" style="font-size: 9pt">{{ $shipment->number }}</div>
            </td>
            <td class="cell">
                <div>{{ $shipment->item_count === 1 ? $tr('item') : $tr('items', ['count' => $shipment->item_count]) }}</div>
                <div>{{ $tr('weight') }}: {{ number_format($shipment->weight_grams) }} g</div>
            </td>
        </tr>
        @if ($shipment->note)
            <tr>
                <td class="cell" colspan="2"><b>{{ $tr('note') }}:</b> {{ $shipment->note }}</td>
            </tr>
        @endif
    </table>
    </div>
@endforeach
</body>
</html>

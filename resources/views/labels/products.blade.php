{{--
    Product barcode labels (owner request 2026-11-16), rendered by mPDF (ProductLabelPdf) on one
    of the LabelTemplate stocks. Each label is an absolutely positioned box at its slot's origin
    in mm, so the print lines up with the die-cut. Text is already cut to fit by the renderer;
    the barcode's size and height come from it too. Hind Siliguri with OpenType layout so a
    Bangla name keeps its conjuncts. Labels print black on white (thermal and laser stock);
    the one grey is the Theme B token value color/text/secondary, named because PDF CSS cannot
    read custom properties. Numerals are Western in both languages (rule 36).

    @var \App\Domain\Catalog\Enums\LabelTemplate $template
    @var list<array{page_break: bool, left: float, top: float, name: string|null, variant: string|null, price: string|null, sku: string, barcode: string, barcode_type: string, barcode_size: float, barcode_height: float}> $labels
    @var array{name_pt: float, variant_pt: float, price_pt: float, sku_pt: float, digits_pt: float, bar_mm: float} $style
    @var float $padding
    @var string $locale
--}}
@php
    [$width, $height] = $template->labelSize();
    $ink = '#000000';
    $muted = '#475569'; // color/text/secondary
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: hindsiliguri; color: {{ $ink }}; }
    .label { position: absolute; width: {{ $width - 2 * $padding }}mm; height: {{ $height - 2 * $padding }}mm; padding: {{ $padding }}mm; overflow: hidden; text-align: center; }
    .name { font-size: {{ $style['name_pt'] }}pt; font-weight: bold; line-height: 1.15; }
    .variant { font-size: {{ $style['variant_pt'] }}pt; color: {{ $muted }}; line-height: 1.15; }
    .code { margin-top: 0.4mm; }
    .digits { font-family: dejavusansmono; font-size: {{ $style['digits_pt'] }}pt; line-height: 1.05; }
    .foot { width: 100%; border-collapse: collapse; margin-top: 0.3mm; }
    .foot td { padding: 0; vertical-align: bottom; }
    .sku { font-family: dejavusansmono; font-size: {{ $style['sku_pt'] }}pt; color: {{ $muted }}; text-align: left; }
    .price { font-size: {{ $style['price_pt'] }}pt; font-weight: bold; text-align: right; white-space: nowrap; }
</style>
</head>
<body>
@foreach ($labels as $label)
    @if ($label['page_break'])
        <pagebreak />
    @endif
    <div class="label" style="left: {{ $label['left'] }}mm; top: {{ $label['top'] }}mm;" lang="{{ $locale }}">
        @if ($label['name'] !== null)
            <div class="name">{{ $label['name'] }}</div>
        @endif
        @if ($label['variant'] !== null)
            <div class="variant">{{ $label['variant'] }}</div>
        @endif
        <div class="code"><barcode code="{{ $label['barcode'] }}" type="{{ $label['barcode_type'] }}" size="{{ $label['barcode_size'] }}" height="{{ $label['barcode_height'] }}" /></div>
        @if ($label['barcode_type'] !== 'EAN13')
            <div class="digits">{{ $label['barcode'] }}</div>
        @endif
        <table class="foot">
            <tr>
                <td class="sku">{{ $label['sku'] }}</td>
                @if ($label['price'] !== null)
                    <td class="price">{{ $label['price'] }}</td>
                @endif
            </tr>
        </table>
    </div>
@endforeach
</body>
</html>

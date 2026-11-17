{{--
    Product barcode labels (owner request 2026-11-16; premium layout Figma 533:82212, 2026-11-17),
    rendered by mPDF (ProductLabelPdf) on one of the LabelTemplate stocks. Each label is an
    absolutely positioned box at its slot's origin in mm, so the print lines up with the die-cut.

    Top to bottom: a header (store name in wide-tracked capitals, SKU on the right) with a
    hairline under it, the product name (bold, up to two lines) and options beside a large
    price, then the barcode, which takes all the height that is left — no blank band at the
    bottom. Black ink only: hierarchy is size and weight (thermal heads speckle grey). The
    renderer has already cut every text to fit and sized the barcode. Hind Siliguri with
    OpenType layout so Bangla keeps its conjuncts (no letter-spacing on Bangla). Numerals are
    Western in both languages (rule 36).

    @var \App\Domain\Catalog\Enums\LabelTemplate $template
    @var list<array<string, mixed>> $labels  see ProductLabelPdf::boxes()
    @var array{padding: float, gap: float, store_pt: float, sku_pt: float, name_pt: float, variant_pt: float, price_pt: float, digits_pt: float} $style
    @var string $locale
--}}
@php
    [$width, $height] = $template->labelSize();
    $pad = $style['padding'];
    $ink = '#000000';
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: hindsiliguri; color: {{ $ink }}; }
    .label { position: absolute; width: {{ $width - 2 * $pad }}mm; height: {{ $height - 2 * $pad }}mm; padding: {{ $pad }}mm; overflow: hidden; }
    table { border-collapse: collapse; width: 100%; }
    td { padding: 0; }
    .head td { vertical-align: middle; line-height: 1.15; }
    .store { font-size: {{ $style['store_pt'] }}pt; font-weight: bold; text-align: left; white-space: nowrap; }
    .store-latin { letter-spacing: 0.14em; }
    .sku { font-family: dejavusansmono; font-size: {{ $style['sku_pt'] }}pt; text-align: right; white-space: nowrap; letter-spacing: 0.04em; }
    .rule { height: 0.18mm; background-color: {{ $ink }}; margin-top: {{ $style['gap'] * 0.6 }}mm; }
    .mid { margin-top: {{ $style['gap'] }}mm; }
    .mid td { vertical-align: top; }
    .name { font-size: {{ $style['name_pt'] }}pt; font-weight: bold; line-height: 1.1; }
    .variant { font-size: {{ $style['variant_pt'] }}pt; line-height: 1.15; margin-top: 0.2mm; }
    .price { text-align: right; white-space: nowrap; font-weight: bold; font-size: {{ $style['price_pt'] }}pt; line-height: 1; }
    .cur { font-size: {{ round($style['price_pt'] * 0.62, 2) }}pt; }
    .code { margin-top: {{ $style['gap'] }}mm; text-align: center; }
    .digits { font-family: dejavusansmono; font-size: {{ $style['digits_pt'] }}pt; line-height: 1.05; text-align: center; }
</style>
</head>
<body>
@foreach ($labels as $label)
    @if ($label['page_break'])
        <pagebreak />
    @endif
    <div class="label" style="left: {{ $label['left'] }}mm; top: {{ $label['top'] }}mm;" lang="{{ $locale }}">
        <table class="head">
            <tr>
                <td class="store {{ $locale === 'bn' ? '' : 'store-latin' }}">{{ $label['store'] ?? '' }}</td>
                <td class="sku">{{ $label['sku'] }}</td>
            </tr>
        </table>
        @if ($label['store'] !== null)
            <div class="rule"></div>
        @endif
        @if ($label['mid_height'] > 0)
            <table class="mid">
                <tr>
                    <td style="width: {{ $label['text_width'] }}mm;">
                        @if ($label['name'] !== null)
                            <div class="name">{{ $label['name'] }}</div>
                        @endif
                        @if ($label['variant'] !== null)
                            <div class="variant">{{ $label['variant'] }}</div>
                        @endif
                    </td>
                    @if ($label['price'] !== null)
                        <td class="price"><span class="cur">৳</span>{{ $label['price'] }}</td>
                    @endif
                </tr>
            </table>
        @endif
        @if ($label['bars'] !== null)
            <div class="code"><img src="{{ $label['bars']['src'] }}" style="width: {{ $label['bars']['width'] }}mm; height: {{ $label['bars']['height'] }}mm;" alt="" /></div>
        @else
            <div class="code"><barcode code="{{ $label['barcode'] }}" type="{{ $label['barcode_type'] }}" size="{{ $label['barcode_size'] }}" height="{{ $label['barcode_height'] }}" /></div>
        @endif
        @if ($label['barcode_type'] !== 'EAN13' && $label['digits'])
            <div class="digits">{{ $label['barcode'] }}</div>
        @endif
    </div>
@endforeach
</body>
</html>

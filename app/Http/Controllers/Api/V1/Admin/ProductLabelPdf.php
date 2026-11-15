<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Enums\LabelTemplate;
use App\Domain\Catalog\Services\Ean13;
use Illuminate\Support\Facades\File;
use Mpdf\Barcode;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * Renders resources/views/labels/products.blade.php — product barcode labels on one of the
 * {@see LabelTemplate} stocks. mPDF with the embedded Hind Siliguri font for the same reason as
 * invoices ({@see OrderInvoicePdf}: Bangla needs OpenType shaping).
 *
 * Every label is an absolutely positioned box at its slot's millimetre origin, so a sheet lines
 * up with the die-cut whatever the text inside does. The renderer, not the view, decides what
 * fits: names are cut at a measured width with an ellipsis (never mid-conjunct), and the
 * barcode's module size and bar height are computed from mPDF's own barcode metrics so it
 * fills the label width without overflowing. A valid EAN-13 is drawn as EAN-13 (mPDF prints
 * the OCR-B digits under it); anything else as Code 128 with the digits printed beneath.
 *
 * @internal
 */
final class ProductLabelPdf
{
    /** Inner padding of every label, mm. */
    private const float PADDING = 1.2;

    private const float PT_TO_MM = 25.4 / 72;

    /**
     * @param  list<array{name: string, variant: string|null, price: string, sku: string, barcode: string}>  $labels  one entry per printed label (copies expanded)
     */
    public function render(LabelTemplate $template, array $labels, string $locale, bool $showPrice, bool $showName): string
    {
        $mpdf = $this->mpdf($template);
        $mpdf->SetTitle('Toy Mart BD — barcode labels');

        $style = $this->style($template, $showName, $showPrice);
        $innerWidth = $template->labelSize()[0] - 2 * self::PADDING;
        $barcodes = new Barcode;

        $boxes = [];
        foreach ($labels as $i => $label) {
            $slot = $i % $template->perPage();
            [$left, $top] = $template->origin($slot);
            $ean = Ean13::isValid($label['barcode']);
            $boxes[] = [
                'page_break' => $i > 0 && $slot === 0,
                'left' => $left,
                'top' => $top,
                'name' => $showName ? $this->fit($mpdf, $label['name'], $innerWidth, $style['name_pt'], true) : null,
                'variant' => $showName && $label['variant'] !== null && $label['variant'] !== '' ? $this->fit($mpdf, $label['variant'], $innerWidth, $style['variant_pt'], false) : null,
                'price' => $showPrice ? $label['price'] : null,
                'sku' => $this->fit($mpdf, $label['sku'], $innerWidth * ($showPrice ? 0.55 : 1), $style['sku_pt'], false),
                'barcode' => $label['barcode'],
                'barcode_type' => $ean ? 'EAN13' : 'C128B',
                // Code 128's digits are a text line under the bars; EAN-13's sit inside its height.
                ...$this->barcodeGeometry($barcodes, $label['barcode'], $ean, $innerWidth, $ean ? $style['bar_mm'] : $style['bar_mm'] - $style['digits_pt'] * self::PT_TO_MM * 1.05),
            ];
        }

        $mpdf->WriteHTML(view('labels.products', [
            'template' => $template,
            'labels' => $boxes,
            'locale' => $locale,
            'style' => $style,
            'padding' => self::PADDING,
        ])->render());

        return $mpdf->Output('', 'S');
    }

    /**
     * Font sizes (pt) and the bar height (mm, digits included) per stock, with more bar when
     * the name or price is left off.
     *
     * @return array{name_pt: float, variant_pt: float, price_pt: float, sku_pt: float, digits_pt: float, bar_mm: float}
     */
    private function style(LabelTemplate $template, bool $showName, bool $showPrice): array
    {
        $style = match ($template) {
            LabelTemplate::Roll38x25 => ['name_pt' => 6.5, 'variant_pt' => 5.5, 'price_pt' => 8.0, 'sku_pt' => 5.0, 'digits_pt' => 6.0, 'bar_mm' => 9.5],
            LabelTemplate::Roll50x25 => ['name_pt' => 7.0, 'variant_pt' => 6.0, 'price_pt' => 9.0, 'sku_pt' => 5.5, 'digits_pt' => 6.5, 'bar_mm' => 9.5],
            LabelTemplate::A4x65 => ['name_pt' => 6.0, 'variant_pt' => 5.0, 'price_pt' => 7.5, 'sku_pt' => 4.5, 'digits_pt' => 5.5, 'bar_mm' => 7.8],
            LabelTemplate::A4x24 => ['name_pt' => 9.0, 'variant_pt' => 7.5, 'price_pt' => 11.0, 'sku_pt' => 6.5, 'digits_pt' => 8.0, 'bar_mm' => 15.0],
        };

        if (! $showName) {
            $style['bar_mm'] += $template === LabelTemplate::A4x24 ? 7.0 : 4.0;
        }
        if (! $showPrice) {
            $style['bar_mm'] += $template === LabelTemplate::A4x24 ? 2.0 : 1.0;
        }

        return $style;
    }

    /**
     * mPDF `size` and `height` for a barcode that is at most `$width` wide (light margins
     * included) and `$barMm` tall, and never larger than the symbology's nominal size.
     *
     * @return array{barcode_size: float, barcode_height: float}
     */
    private function barcodeGeometry(Barcode $barcodes, string $code, bool $ean, float $width, float $barMm): array
    {
        $metrics = $barcodes->getBarcodeArray($code, $ean ? 'EAN13' : 'C128B');
        if ($metrics === false) {
            return ['barcode_size' => 1.0, 'barcode_height' => 1.0];
        }

        $natural = ($metrics['maxw'] + $metrics['lightmL'] + $metrics['lightmR']) * $metrics['nom-X'];
        $size = min(1.0, $width / $natural);
        // EAN-13's nominal height includes its digits; Code 128 gets its digits as text below.
        $height = $barMm / ($metrics['nom-H'] * $size);

        return ['barcode_size' => round($size, 4), 'barcode_height' => round($height, 4)];
    }

    /**
     * `$text` cut to `$width` mm at `$pt` (two lines' worth when `$twoLines`), on grapheme
     * boundaries, with an ellipsis. A trailing Bangla hasant (্) is dropped so a cut conjunct
     * never shows a dangling virama.
     */
    private function fit(Mpdf $mpdf, string $text, float $width, float $pt, bool $twoLines): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $mpdf->SetFont(OrderInvoicePdf::FONT, '', $pt, false, false);
        // Wrapping loses part of a line at a word break; 0.85 keeps two lines inside two lines.
        $limit = $twoLines ? $width * 2 * 0.85 : $width * 0.97;
        if ($mpdf->GetStringWidth($text) <= $limit) {
            return $text;
        }

        $graphemes = preg_split('/(\X)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];
        $low = 0;
        $high = count($graphemes);
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if ($mpdf->GetStringWidth(implode('', array_slice($graphemes, 0, $mid)).'…') <= $limit) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        $cut = rtrim(implode('', array_slice($graphemes, 0, $low)));
        $cut = preg_replace('/\x{09CD}+$/u', '', $cut) ?? $cut;

        return $cut.'…';
    }

    private function mpdf(LabelTemplate $template): Mpdf
    {
        $tempDir = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($tempDir);

        $defaults = (new ConfigVariables)->getDefaults();
        $fonts = (new FontVariables)->getDefaults();

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => $template->pageSize(),
            'orientation' => 'P',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'margin_header' => 0,
            'margin_footer' => 0,
            'tempDir' => $tempDir,
            'fontDir' => [...$defaults['fontDir'], resource_path('fonts/invoice')],
            'fontdata' => $fonts['fontdata'] + [
                OrderInvoicePdf::FONT => [
                    'R' => 'HindSiliguri-Regular.ttf',
                    'B' => 'HindSiliguri-SemiBold.ttf',
                    'useOTL' => 0xFF,
                ],
            ],
            'default_font' => OrderInvoicePdf::FONT,
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
        ]);
    }
}

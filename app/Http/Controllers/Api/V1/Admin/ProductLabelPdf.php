<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Enums\LabelTemplate;
use App\Domain\Catalog\Services\Ean13;
use App\Support\MpdfCache;
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
 * Without the digits (`$showDigits` false): Code 128 just drops its text line. mPDF has no
 * switch for EAN-13's numerals — its `text` attribute only adds the code *above* the bars and
 * the bottom numerals are always drawn — so the bars are drawn here instead, as an SVG built
 * from mPDF's own module pattern ({@see self::barsSvg()}), same width and quiet zones.
 *
 * @internal
 */
final class ProductLabelPdf
{
    private const float PT_TO_MM = 25.4 / 72;

    /** The barcode never gets shorter than this share of the label's inner height. */
    private const float MIN_BAR_SHARE = 0.38;

    /**
     * @param  list<array{name: string, variant: string|null, price: string, sku: string, barcode: string}>  $labels  one entry per printed label (copies expanded)
     * @param  string|null  $store  the store name printed at the top, or null for none
     */
    public function render(LabelTemplate $template, array $labels, string $locale, bool $showPrice, bool $showName, bool $showOptions = true, ?string $store = null, bool $showDigits = true): string
    {
        return MpdfCache::exclusive(fn (): string => $this->renderLocked($template, $labels, $locale, $showPrice, $showName, $showOptions, $store, $showDigits));
    }

    /**
     * The view data for each label — layout decided here, drawn by the view. Public for tests.
     *
     * @param  list<array{name: string, variant: string|null, price: string, sku: string, barcode: string}>  $labels
     * @return list<array<string, mixed>>
     */
    public function boxes(Mpdf $mpdf, LabelTemplate $template, array $labels, string $locale, bool $showPrice, bool $showName, bool $showOptions, ?string $store, bool $showDigits): array
    {
        $style = self::style($template);
        [$width, $height] = $template->labelSize();
        $innerW = $width - 2 * $style['padding'];
        $innerH = $height - 2 * $style['padding'];
        $barcodes = new Barcode;
        $line = static fn (float $pt, float $lh = 1.15): float => $pt * self::PT_TO_MM * $lh;

        $storeText = $store !== null && trim($store) !== '' ? ($locale === 'bn' ? trim($store) : mb_strtoupper(trim($store))) : null;
        $svgs = [];
        $boxes = [];
        foreach ($labels as $i => $label) {
            $slot = $i % $template->perPage();
            [$left, $top] = $template->origin($slot);
            $ean = Ean13::isValid($label['barcode']);

            // Header: store name (wide-tracked capitals) on the left, SKU on the right.
            $sku = $this->fit($mpdf, $label['sku'], $innerW * 0.42, $style['sku_pt'], false);
            $storeLine = $storeText !== null ? $this->fit($mpdf, $storeText, $innerW * 0.56, $style['store_pt'], false, $locale === 'bn' ? 0.0 : 0.14) : null;
            $used = $line(max($style['store_pt'], $style['sku_pt']));
            $blocks = 1;
            if ($storeLine !== null) {
                $used += 0.18;
                $blocks++;
            }

            // Name and options on the left, the price on the right.
            $price = $showPrice ? $label['price'] : null;
            $priceDigits = $price !== null ? trim(str_replace('৳', '', $price)) : null;
            $priceW = 0.0;
            if ($priceDigits !== null) {
                $mpdf->SetFont(OrderInvoicePdf::FONT, 'B', $style['price_pt'], false, false);
                $priceW = $mpdf->GetStringWidth($priceDigits);
                $mpdf->SetFont(OrderInvoicePdf::FONT, 'B', $style['price_pt'] * 0.62, false, false);
                $priceW += $mpdf->GetStringWidth('৳') + 0.3;
            }
            $textW = max(8.0, $innerW - ($priceW > 0 ? $priceW + 1.0 : 0.0));
            $variant = $showOptions && $label['variant'] !== null && $label['variant'] !== '' ? $this->fit($mpdf, $label['variant'], $textW, $style['variant_pt'], false) : null;

            $name = null;
            $nameLines = 0;
            if ($showName) {
                $one = $this->fit($mpdf, $label['name'], $textW, $style['name_pt'], false, 0.0, 'B');
                $name = $one;
                $nameLines = 1;
                $twoAllowed = $template !== LabelTemplate::A4x65;
                if ($twoAllowed && str_ends_with($one, '…')) {
                    $twoLineUsed = $used + $line($style['name_pt'], 1.1) * 2 + ($variant !== null ? $line($style['variant_pt']) + 0.2 : 0.0);
                    if ($innerH - $twoLineUsed - $style['gap'] * ($blocks + 1) >= $innerH * self::MIN_BAR_SHARE) {
                        $name = $this->fit($mpdf, $label['name'], $textW, $style['name_pt'], true, 0.0, 'B');
                        $nameLines = 2;
                    }
                }
            }
            $textH = ($nameLines > 0 ? $line($style['name_pt'], 1.1) * $nameLines : 0.0) + ($variant !== null ? $line($style['variant_pt']) + ($nameLines > 0 ? 0.2 : 0.0) : 0.0);
            $midH = max($textH, $price !== null ? $line($style['price_pt'], 1.0) : 0.0);
            if ($midH > 0) {
                $used += $midH;
                $blocks++;
            }

            // The barcode takes the rest of the label, so nothing is left blank at the bottom.
            $barMm = max(4.0, round($innerH - $used - $style['gap'] * $blocks - 0.15, 3));
            // Code 128's digits are a text line under the bars; EAN-13's sit inside its height.
            $codeBars = $ean || ! $showDigits ? $barMm : $barMm - $line($style['digits_pt'], 1.05);
            $geometry = $this->barcodeGeometry($barcodes, $label['barcode'], $ean, $innerW, $codeBars);
            $bars = null;
            if ($ean && ! $showDigits) {
                $bars = $svgs[$label['barcode'].'|'.$barMm] ??= $this->barsSvg($barcodes, $label['barcode'], $geometry['barcode_size'], $barMm);
            }

            $boxes[] = [
                'page_break' => $i > 0 && $slot === 0,
                'left' => $left,
                'top' => $top,
                'store' => $storeLine,
                'sku' => $sku,
                'name' => $name,
                'name_lines' => $nameLines,
                'variant' => $variant,
                'price' => $priceDigits,
                'text_width' => round($textW, 3),
                'mid_height' => round($midH, 3),
                'barcode' => $label['barcode'],
                'barcode_type' => $ean ? 'EAN13' : 'C128B',
                'digits' => $showDigits,
                'bars' => $bars,
                'bar_mm' => $barMm,
                ...$geometry,
            ];
        }

        return $boxes;
    }

    /** @param  list<array{name: string, variant: string|null, price: string, sku: string, barcode: string}>  $labels */
    private function renderLocked(LabelTemplate $template, array $labels, string $locale, bool $showPrice, bool $showName, bool $showOptions, ?string $store, bool $showDigits): string
    {
        $mpdf = $this->mpdf($template);
        $mpdf->SetTitle('Smart Junior — barcode labels');

        $mpdf->WriteHTML(view('labels.products', [
            'template' => $template,
            'labels' => $this->boxes($mpdf, $template, $labels, $locale, $showPrice, $showName, $showOptions, $store, $showDigits),
            'locale' => $locale,
            'style' => self::style($template),
        ])->render());

        return $mpdf->Output('', 'S');
    }

    /**
     * Type sizes (pt), padding and gap (mm) per stock — Figma "Print · Barcode labels ·
     * premium" 533:82212. Black ink only: hierarchy comes from size and weight, never grey,
     * because thermal heads dither grey into speckle.
     *
     * @return array{padding: float, gap: float, store_pt: float, sku_pt: float, name_pt: float, variant_pt: float, price_pt: float, digits_pt: float}
     */
    public static function style(LabelTemplate $template): array
    {
        return match ($template) {
            LabelTemplate::Roll38x25 => ['padding' => 1.6, 'gap' => 0.7, 'store_pt' => 4.6, 'sku_pt' => 4.2, 'name_pt' => 6.6, 'variant_pt' => 5.2, 'price_pt' => 10.0, 'digits_pt' => 5.4],
            LabelTemplate::Roll50x25 => ['padding' => 1.6, 'gap' => 0.7, 'store_pt' => 5.0, 'sku_pt' => 4.6, 'name_pt' => 7.2, 'variant_pt' => 5.6, 'price_pt' => 11.5, 'digits_pt' => 5.8],
            LabelTemplate::A4x65 => ['padding' => 1.2, 'gap' => 0.5, 'store_pt' => 4.2, 'sku_pt' => 3.9, 'name_pt' => 6.0, 'variant_pt' => 4.8, 'price_pt' => 9.0, 'digits_pt' => 5.0],
            LabelTemplate::A4x24 => ['padding' => 1.6, 'gap' => 1.0, 'store_pt' => 6.4, 'sku_pt' => 5.6, 'name_pt' => 9.5, 'variant_pt' => 7.0, 'price_pt' => 15.0, 'digits_pt' => 7.4],
        };
    }

    /**
     * An EAN-13's bars with no numerals, as an SVG data URI for an <img>: mPDF's own module
     * pattern at `$size` (so the same width and quiet zones as its `<barcode>`), `$heightMm` tall.
     *
     * @return array{src: string, width: float, height: float}|null
     */
    private function barsSvg(Barcode $barcodes, string $code, float $size, float $heightMm): ?array
    {
        $metrics = $barcodes->getBarcodeArray($code, 'EAN13');
        if ($metrics === false) {
            return null;
        }

        $module = $metrics['nom-X'] * $size;
        $x = $metrics['lightmL'] * $module;
        $rects = '';
        foreach ($metrics['bcode'] as $bar) {
            $w = $bar['w'] * $module;
            if ($bar['t']) {
                $rects .= sprintf('<rect x="%.4F" y="0" width="%.4F" height="%.4F" fill="#000000"/>', $x, $w, $heightMm);
            }
            $x += $w;
        }
        $width = ($metrics['maxw'] + $metrics['lightmL'] + $metrics['lightmR']) * $module;
        $svg = sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%1$.4Fmm" height="%2$.4Fmm" viewBox="0 0 %1$.4F %2$.4F">%3$s</svg>', $width, $heightMm, $rects);

        return ['src' => 'data:image/svg+xml;base64,'.base64_encode($svg), 'width' => round($width, 3), 'height' => round($heightMm, 3)];
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
    private function fit(Mpdf $mpdf, string $text, float $width, float $pt, bool $twoLines, float $tracking = 0.0, string $weight = ''): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $mpdf->SetFont(OrderInvoicePdf::FONT, $weight, $pt, false, false);
        // Wrapping loses part of a line at a word break; 0.85 keeps two lines inside two lines.
        $limit = $twoLines ? $width * 2 * 0.85 : $width * 0.97;
        // Letter-spacing (an em fraction) widens every grapheme by the same amount.
        $measure = static fn (string $t): float => $mpdf->GetStringWidth($t) + $tracking * $pt * self::PT_TO_MM * mb_strlen($t);
        if ($measure($text) <= $limit) {
            return $text;
        }

        $graphemes = preg_split('/(\X)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];
        $low = 0;
        $high = count($graphemes);
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if ($measure(implode('', array_slice($graphemes, 0, $mid)).'…') <= $limit) {
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
        $tempDir = MpdfCache::path();

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

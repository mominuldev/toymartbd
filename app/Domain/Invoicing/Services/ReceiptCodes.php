<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Mpdf\Barcode;

/**
 * The machine-readable marks on the POS counter receipt (Settings › Invoices, receipts &
 * labels › Thermal receipt, Figma 529:81638), as standalone SVG strings the browser prints:
 *
 * - the receipt barcode — Code 128 (set B) of the order number, scanned at the counter to find
 *   the sale for an exchange. The bar pattern is mPDF's own encoder (already used for product
 *   and shipment labels); only the drawing is ours.
 * - the WhatsApp QR — `https://wa.me/<digits>` for the store's WhatsApp number, drawn by
 *   bacon/bacon-qr-code (already used for two-factor enrolment).
 *
 * Both are built from installed libraries; nothing is fetched and nothing is cached.
 */
final class ReceiptCodes
{
    /** Bar height in modules (the SVG stretches to the paper width; height scales with it). */
    private const int BAR_HEIGHT = 40;

    /** Quiet zone each side, in modules (Code 128 asks for at least 10). */
    private const int QUIET = 10;

    /** Code 128 of `$text` as an SVG of black bars on white, or null when it cannot be encoded. */
    public function code128Svg(string $text): ?string
    {
        if ($text === '' || preg_match('/^[\x20-\x7E]+$/', $text) !== 1) {
            return null;
        }
        $array = (new Barcode)->getBarcodeArray($text, 'C128B');
        if (! is_array($array) || ! is_array($array['bcode'] ?? null)) {
            return null;
        }

        $x = self::QUIET;
        $rects = '';
        foreach ($array['bcode'] as $bar) {
            $width = (int) ($bar['w'] ?? 0);
            if (($bar['t'] ?? false) === true && $width > 0) {
                $rects .= sprintf('<rect x="%d" y="0" width="%d" height="%d"/>', $x, $width, self::BAR_HEIGHT);
            }
            $x += $width;
        }
        $total = $x + self::QUIET;

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" preserveAspectRatio="none" shape-rendering="crispEdges">'
            .'<rect width="100%%" height="100%%" fill="#fff"/><g fill="#000">%3$s</g></svg>',
            $total,
            self::BAR_HEIGHT,
            $rects,
        );
    }

    /** The `wa.me` chat link for an E.164 number (`+8801711000222` → `https://wa.me/8801711000222`), or null. */
    public static function whatsappLink(?string $e164): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $e164) ?? '';

        return strlen($digits) >= 8 ? 'https://wa.me/'.$digits : null;
    }

    /** A QR code of `$text` as an SVG (black on white, no margin of its own). */
    public function qrSvg(string $text): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(160, 0), new SvgImageBackEnd));

        return $writer->writeString($text);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Models\Order;
use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * Renders resources/views/labels/shipments.blade.php — one 100 × 150 mm shipping label per
 * page (the common 4 × 6 in thermal size). mPDF with the embedded Hind Siliguri font for the
 * same reason as invoices ({@see OrderInvoicePdf}: Bangla needs OpenType shaping). The
 * consignment id is printed as text AND as an mPDF built-in Code 128 barcode; no QR, because
 * mPDF's QR type needs the separate mpdf/qrcode package, which is not installed.
 *
 * @internal
 */
final class ShipmentLabelPdf
{
    /** @param  list<array{shipment: Shipment, order: Order}>  $labels */
    public function render(array $labels, string $locale): string
    {
        $tempDir = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($tempDir);

        $defaults = (new ConfigVariables)->getDefaults();
        $fonts = (new FontVariables)->getDefaults();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => [100, 150],
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_top' => 5,
            'margin_bottom' => 5,
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
        $mpdf->SetTitle('Toy Mart BD — shipping labels');
        $mpdf->WriteHTML(view('labels.shipments', ['labels' => $labels, 'locale' => $locale])->render());

        return $mpdf->Output('', 'S');
    }
}

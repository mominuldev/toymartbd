<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * Renders issued invoices — customer and supplier — from their snapshots through
 * resources/views/invoices/invoice.blade.php to one PDF (P4-ORD-08, P8 N-13).
 *
 * mPDF, not dompdf: Bangla needs OpenType shaping — conjuncts (ন্ট্র, ক্ষ), reph and
 * pre-base vowel reordering (ি, ে) — which dompdf does not do (verified: it prints
 * "রিমোট" as "রমিোট" with broken hasant clusters). mPDF's OTL engine with the embedded
 * Hind Siliguri font (SIL OFL, resources/fonts/invoice) renders them correctly; Noto
 * Sans Bengali was tried and fails mPDF's GPOS/GSUB parser.
 *
 * @internal
 */
final class OrderInvoicePdf
{
    public const string FONT = 'hindsiliguri';

    /** @param  list<array{invoice: Invoice, locale: string}>  $invoices */
    public function render(array $invoices): string
    {
        $tempDir = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($tempDir);

        $defaults = (new ConfigVariables)->getDefaults();
        $fonts = (new FontVariables)->getDefaults();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 14,
            'tempDir' => $tempDir,
            'fontDir' => [...$defaults['fontDir'], resource_path('fonts/invoice')],
            'fontdata' => $fonts['fontdata'] + [
                self::FONT => [
                    'R' => 'HindSiliguri-Regular.ttf',
                    'B' => 'HindSiliguri-SemiBold.ttf',
                    'useOTL' => 0xFF,
                ],
            ],
            'default_font' => self::FONT,
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
        ]);
        $mpdf->SetTitle(count($invoices) === 1 ? $invoices[0]['invoice']->number : 'Toy Mart BD — invoices');
        $mpdf->WriteHTML(view('invoices.invoice', ['invoices' => $invoices])->render());

        return $mpdf->Output('', 'S');
    }
}

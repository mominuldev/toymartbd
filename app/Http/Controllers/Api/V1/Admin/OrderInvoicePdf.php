<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\CreditNote;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\PrintSettings;
use App\Support\MpdfCache;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * Renders issued invoices — customer and supplier — and credit notes from their snapshots through
 * resources/views/invoices/invoice.blade.php to one PDF (P4-ORD-08, P8 N-13).
 *
 * mPDF, not dompdf: Bangla needs OpenType shaping — conjuncts (ন্ট্র, ক্ষ), reph and
 * pre-base vowel reordering (ি, ে) — which dompdf does not do (verified: it prints
 * "রিমোট" as "রমিোট" with broken hasant clusters). mPDF's OTL engine with the embedded
 * Hind Siliguri font (SIL OFL, resources/fonts/invoice) renders them correctly; Noto
 * Sans Bengali was tried and fails mPDF's GPOS/GSUB parser.
 *
 * Customer invoices follow Settings › Invoices, receipts & labels › A4 invoice
 * ({@see PrintSettings::invoice()}): paper, language, colour and the optional parts. They are
 * read at render time — presentation only; the snapshot is never touched.
 *
 * @internal
 */
final class OrderInvoicePdf
{
    public const string FONT = 'hindsiliguri';

    public function __construct(private readonly PrintSettings $settings) {}

    /**
     * `locale` is `en`, `bn` or `both` (customer invoices: English with Bangla labels beneath).
     * `$print` overrides the saved invoice settings (the settings page previews a draft).
     *
     * @param  list<array{invoice: Invoice, locale: string}>  $invoices
     * @param  array<string, string|bool|null>|null  $print
     */
    public function render(array $invoices, ?array $print = null, bool $sample = false): string
    {
        $print ??= $this->settings->invoice();

        return MpdfCache::exclusive(fn (): string => $this->renderLocked($invoices, $print, $sample));
    }

    /**
     * The HTML handed to mPDF — what tests assert on, since PDF bytes are compressed.
     *
     * @param  list<array{invoice: Invoice, locale: string}>  $invoices
     * @param  array<string, string|bool|null>|null  $print
     */
    public function html(array $invoices, ?array $print = null, bool $sample = false): string
    {
        return view('invoices.invoice', ['invoices' => $invoices, 'print' => $print ?? $this->settings->invoice(), 'sample' => $sample])->render();
    }

    /**
     * The page size: the chosen paper when every document is a customer invoice; a supplier
     * invoice keeps A4.
     *
     * @param  list<array{invoice: Invoice, locale: string}>  $invoices
     * @param  array<string, string|bool|null>  $print
     */
    public static function format(array $invoices, array $print): string
    {
        foreach ($invoices as $entry) {
            if ($entry['invoice']->type !== InvoiceType::Sales) {
                return 'A4';
            }
        }

        return $print['paper'] === 'a5' ? 'A5' : 'A4';
    }

    /**
     * The language an invoice prints in: the one asked for, else the owner's choice for customer
     * invoices (`en`, `bn` or `both`). A supplier invoice without one prints in English.
     */
    public function locale(Invoice $invoice, ?string $requested): string
    {
        if ($requested !== null) {
            return $requested;
        }

        return $invoice->type === InvoiceType::Sales ? (string) $this->settings->invoice()['language'] : 'en';
    }

    /**
     * The storefront's track-order page with the order number filled in (the same URL the order
     * e-mails carry). Never the phone: tracking still asks for it (rule 22).
     */
    public static function trackUrl(string $orderNumber, bool $bn): string
    {
        $store = config('notifications.store_url');

        return 'https://'.(is_string($store) ? $store : 'toymartbd.com').($bn ? '/bn' : '').'/track?order='.rawurlencode($orderNumber);
    }

    /**
     * A QR code of `$text` as an SVG data URI for an `<img>` — drawn by bacon/bacon-qr-code
     * (already installed for two-factor enrolment); mPDF's own `<barcode type="QR">` needs a
     * package this app does not ship.
     */
    public static function qrDataUri(string $text): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(160, 1), new SvgImageBackEnd)))->writeString($text);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * @param  list<array{invoice: Invoice, locale: string}>  $invoices
     * @param  array<string, string|bool|null>  $print
     */
    private function renderLocked(array $invoices, array $print, bool $sample): string
    {
        $mpdf = $this->mpdf(self::format($invoices, $print));
        $mpdf->SetTitle(count($invoices) === 1 ? $invoices[0]['invoice']->number : 'Toy Mart BD — invoices');
        $mpdf->WriteHTML($this->html($invoices, $print, $sample));

        return $mpdf->Output('', 'S');
    }

    /**
     * Credit notes (P8 batch 2), from their snapshots, through
     * resources/views/invoices/credit-note.blade.php — same engine, font and page as invoices.
     *
     * @param  list<array{credit_note: CreditNote, locale: string}>  $notes
     */
    public function renderCreditNotes(array $notes): string
    {
        return MpdfCache::exclusive(fn (): string => $this->renderCreditNotesLocked($notes));
    }

    /** @param  list<array{credit_note: CreditNote, locale: string}>  $notes */
    private function renderCreditNotesLocked(array $notes): string
    {
        $mpdf = $this->mpdf();
        $mpdf->SetTitle(count($notes) === 1 ? $notes[0]['credit_note']->number : 'Toy Mart BD — credit notes');
        $mpdf->WriteHTML(view('invoices.credit-note', ['notes' => $notes])->render());

        return $mpdf->Output('', 'S');
    }

    private function mpdf(string $format = 'A4'): Mpdf
    {
        $margin = $format === 'A5' ? 10 : 14;
        $tempDir = MpdfCache::path();

        $defaults = (new ConfigVariables)->getDefaults();
        $fonts = (new FontVariables)->getDefaults();

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => $format,
            'margin_left' => $margin,
            'margin_right' => $margin,
            'margin_top' => $margin,
            'margin_bottom' => $margin,
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
    }
}

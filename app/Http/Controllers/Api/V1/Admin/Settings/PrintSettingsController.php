<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Invoicing\Actions\UpdatePrintSettings;
use App\Domain\Invoicing\Services\PrintSettings;
use App\Domain\Invoicing\Services\SampleInvoice;
use App\Http\Controllers\Api\V1\Admin\OrderInvoiceController;
use App\Http\Controllers\Api\V1\Admin\OrderInvoicePdf;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Settings\InvoicePreviewRequest;
use App\Http\Requests\Admin\Settings\UpdatePrintSettingsRequest;
use App\Http\Resources\Admin\PrintSettingsResource;
use App\Models\Enums\Permission;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Response;

/**
 * Settings › Invoices, receipts & labels (Figma 528:81351): what the A4 invoice, the POS
 * receipt and barcode labels show. Read with settings.view, save with settings.update.
 */
final class PrintSettingsController extends ApiController
{
    /** `GET /settings/print` */
    public function show(PrintSettings $print): PrintSettingsResource
    {
        $this->authorize(Permission::SettingsView->value);

        return self::view($print);
    }

    /** `PUT /settings/print` */
    public function update(UpdatePrintSettingsRequest $request, UpdatePrintSettings $update, PrintSettings $print): PrintSettingsResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $update->handle($request->documents(), is_int($userId) ? $userId : null);

        return self::view($print);
    }

    /**
     * `GET /settings/print/invoice-preview` — a fixed sample customer invoice as a PDF, through the
     * real template with today's company details, on the saved invoice settings overlaid with
     * any sent as query parameters (the page's unsaved draft). Nothing is issued or stored.
     */
    #[OpenApiResponse(200, 'The sample invoice PDF', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    public function invoicePreview(InvoicePreviewRequest $request, PrintSettings $print, SampleInvoice $sample, OrderInvoicePdf $pdf): Response
    {
        $settings = PrintSettings::clean('invoice', [...$print->invoice(), ...$request->overrides()]);
        $invoice = $sample->make();

        return OrderInvoiceController::pdf(
            $pdf->render([['invoice' => $invoice, 'locale' => (string) $settings['language']]], $settings, sample: true),
            'invoice-preview.pdf',
            [],
        );
    }

    private static function view(PrintSettings $print): PrintSettingsResource
    {
        return new PrintSettingsResource([...$print->all(), 'saved' => $print->saved()]);
    }
}

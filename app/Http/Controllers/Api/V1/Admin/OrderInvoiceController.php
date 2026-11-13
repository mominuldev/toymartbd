<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Invoicing\Actions\IssueSalesInvoice;
use App\Domain\Invoicing\Exceptions\InvoiceNotIssuable;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Order\OrderInvoicesRequest;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Response;
use Throwable;

/**
 * Customer invoice / packing slip PDFs (P4-ORD-08, P8 N-13) — one A4 page per order, in the
 * customer's preferred language unless `locale` says otherwise. Needs orders.view and
 * customers.view_pii (the slip carries the full phone and street address, rule 20).
 *
 * The first print of an order ISSUES its invoice (IssueSalesInvoice: gap-free `INV-YY-NNNNNN`,
 * company + customer + lines + totals snapshotted); every later print re-renders that same
 * snapshot, so a reprint shows the details current at issue.
 */
final class OrderInvoiceController extends ApiController
{
    /** `GET /orders/{order}/invoice` — one order's invoice as `application/pdf` (issued on first print). */
    #[OpenApiResponse(200, 'The invoice PDF; header `X-Invoice-Numbers` = `{order id}:{invoice number}`', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    #[OpenApiResponse(409, 'The order is cancelled and was never invoiced (`conflict`)')]
    public function show(OrderInvoicesRequest $request, Order $order, IssueSalesInvoice $issue, OrderInvoicePdf $pdf): Response
    {
        $invoice = $issue->handle($order, self::userId($request));

        return self::pdf(
            $pdf->render([['invoice' => $invoice, 'locale' => self::locale($invoice, $request->locale())]]),
            $invoice->number.'.pdf',
            ['X-Invoice-Numbers' => $order->id.':'.$invoice->number],
        );
    }

    /**
     * `POST /orders/bulk/invoices` — the selected orders' invoices in one PDF, in the order
     * requested; each order's invoice is issued on its first print. Never all-or-nothing,
     * the outcome is reported per order in headers (comma-separated):
     *
     *  - `X-Invoice-Numbers` — `{order id}:{invoice number}` for every order printed;
     *  - `X-Orders-Not-Found` — ids that do not exist;
     *  - `X-Orders-Not-Invoiced` — `{order id}:{reason}` (`cancelled`, `error`) left out.
     *
     * 404 when none of the ids exist; 409 when some exist but none could be printed.
     */
    #[OpenApiResponse(200, 'The invoices PDF', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    #[OpenApiResponse(404, 'None of the requested orders exist (`not-found`)')]
    #[OpenApiResponse(409, 'None of the existing orders could be invoiced (`conflict`)')]
    public function bulk(OrderInvoicesRequest $request, IssueSalesInvoice $issue, OrderInvoicePdf $pdf): Response
    {
        $ids = $request->orderIds();
        $orders = Order::query()->whereKey($ids)->get()->keyBy('id');
        $missing = array_values(array_filter($ids, static fn (int $id): bool => ! $orders->has($id)));
        abort_if($orders->isEmpty(), 404);

        $userId = self::userId($request);
        $printed = [];
        $refused = [];
        $numbers = [];
        foreach ($ids as $id) {
            if (! $orders->has($id)) {
                continue;
            }
            try {
                $invoice = $issue->handle($orders[$id], $userId);
            } catch (InvoiceNotIssuable $e) {
                $refused[] = $id.':'.$e->reason;

                continue;
            } catch (Throwable $e) {
                report($e);
                $refused[] = $id.':error';

                continue;
            }
            $printed[] = ['invoice' => $invoice, 'locale' => self::locale($invoice, $request->locale())];
            $numbers[] = $id.':'.$invoice->number;
        }

        abort_if($printed === [], 409, (string) __('invoices.conflict.none_printable'));

        return self::pdf($pdf->render($printed), 'invoices-'.now()->format('Ymd-His').'.pdf', [
            'X-Invoice-Numbers' => implode(',', $numbers),
            'X-Orders-Not-Found' => $missing === [] ? null : implode(',', $missing),
            'X-Orders-Not-Invoiced' => $refused === [] ? null : implode(',', $refused),
        ]);
    }

    /** The requested language, else the customer's preferred language at issue. */
    public static function locale(Invoice $invoice, ?string $requested): string
    {
        return $requested ?? (($invoice->issued_to['preferred_language'] ?? 'bn') === 'en' ? 'en' : 'bn');
    }

    /** @param  array<string, string|null>  $headers */
    public static function pdf(string $bytes, string $filename, array $headers): Response
    {
        return response($bytes, 200, array_filter([
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'Access-Control-Expose-Headers' => 'X-Invoice-Numbers, X-Orders-Not-Found, X-Orders-Not-Invoiced',
        ] + $headers, static fn (?string $v): bool => $v !== null));
    }

    private static function userId(OrderInvoicesRequest $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}

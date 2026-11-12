<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Order\OrderInvoicesRequest;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Response;

/**
 * Invoice / packing slip PDFs (P4-ORD-08) — one A4 page per order, in the customer's
 * preferred language unless `locale` says otherwise. Needs orders.view and
 * customers.view_pii (the slip carries the full phone and street address, rule 20).
 */
final class OrderInvoiceController extends ApiController
{
    /** `GET /orders/{order}/invoice` — one order's invoice as `application/pdf`. */
    #[OpenApiResponse(200, 'The invoice PDF', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    public function show(OrderInvoicesRequest $request, Order $order, OrderInvoicePdf $pdf): Response
    {
        return self::pdf($pdf->render([self::invoice($order, $request->locale())]), 'invoice-'.$order->number.'.pdf', []);
    }

    /**
     * `POST /orders/bulk/invoices` — the selected orders' invoices in one PDF, in the order
     * requested. Never all-or-nothing: ids that do not exist are left out and listed in
     * the `X-Orders-Not-Found` header (comma-separated); if none exist it is a 404.
     */
    #[OpenApiResponse(200, 'The invoices PDF', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    #[OpenApiResponse(404, 'None of the requested orders exist (`not-found`)')]
    public function bulk(OrderInvoicesRequest $request, OrderInvoicePdf $pdf): Response
    {
        $ids = $request->orderIds();
        $orders = Order::query()->with(['items', 'customer'])->whereKey($ids)->get()->keyBy('id');
        $missing = array_values(array_filter($ids, static fn (int $id): bool => ! $orders->has($id)));
        abort_if($orders->isEmpty(), 404);

        $invoices = [];
        foreach ($ids as $id) {
            if ($orders->has($id)) {
                $invoices[] = self::invoice($orders[$id], $request->locale());
            }
        }

        return self::pdf($pdf->render($invoices), 'invoices-'.now()->format('Ymd-His').'.pdf', $missing);
    }

    /** @return array{order: Order, locale: string} */
    private static function invoice(Order $order, ?string $locale): array
    {
        $order->loadMissing(['items', 'customer']);

        return ['order' => $order, 'locale' => $locale ?? ($order->customer->preferred_language === 'en' ? 'en' : 'bn')];
    }

    /** @param  list<int>  $missing */
    private static function pdf(string $bytes, string $filename, array $missing): Response
    {
        return response($bytes, 200, array_filter([
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Orders-Not-Found' => $missing === [] ? null : implode(',', $missing),
        ]));
    }
}

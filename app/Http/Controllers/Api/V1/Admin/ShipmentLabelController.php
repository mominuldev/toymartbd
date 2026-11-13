<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Shipment\ShipmentLabelsRequest;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Response;

/**
 * Shipping labels (P6). A label needs the courier's consignment id — a `submitted` shipment
 * has none yet (rule 24d: bulk booking returns no ids), so it cannot be labelled
 * (04-admin-spec A16: "A label cannot be printed before the consignment ID arrives").
 */
final class ShipmentLabelController extends ApiController
{
    /** `GET /shipments/{shipment}/label` — one label as `application/pdf`; 409 before the consignment id exists. */
    #[OpenApiResponse(200, 'The label PDF', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    #[OpenApiResponse(409, 'No consignment id yet (`conflict`)')]
    public function show(ShipmentLabelsRequest $request, Shipment $shipment, ShipmentLabelPdf $pdf): Response
    {
        abort_if($shipment->consignment_id === null, 409, 'A label cannot be printed before the courier issues a consignment ID.');
        $order = Order::query()->findOrFail($shipment->order_id);
        $shipment->loadMissing('provider');

        return self::pdf($pdf->render([['shipment' => $shipment, 'order' => $order]], $request->labelLocale()), 'label-'.$shipment->number.'.pdf', []);
    }

    /**
     * `POST /shipments/labels` — labels for the selected shipments in one PDF, in the order
     * requested. Shipments that do not exist or have no consignment id yet are left out and
     * listed in `X-Shipments-Skipped`; if nothing is printable it is a 409.
     */
    #[OpenApiResponse(200, 'The labels PDF', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    #[OpenApiResponse(409, 'None of the shipments has a consignment id (`conflict`)')]
    public function bulk(ShipmentLabelsRequest $request, ShipmentLabelPdf $pdf): Response
    {
        $ids = $request->shipmentIds();
        $shipments = Shipment::query()->with('provider')->whereKey($ids)->whereNotNull('consignment_id')->get()->keyBy('id');
        $orders = Order::query()->whereKey($shipments->pluck('order_id')->unique()->all())->get()->keyBy('id');
        $skipped = array_values(array_filter($ids, static fn (int $id): bool => ! $shipments->has($id)));
        abort_if($shipments->isEmpty(), 409, 'None of these shipments has a consignment ID yet.');

        $labels = [];
        foreach ($ids as $id) {
            if ($shipments->has($id) && $orders->has($shipments[$id]->order_id)) {
                $labels[] = ['shipment' => $shipments[$id], 'order' => $orders[$shipments[$id]->order_id]];
            }
        }

        return self::pdf($pdf->render($labels, $request->labelLocale()), 'labels-'.now()->format('Ymd-His').'.pdf', $skipped);
    }

    /** @param  list<int>  $skipped */
    private static function pdf(string $bytes, string $filename, array $skipped): Response
    {
        return response($bytes, 200, array_filter([
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Shipments-Skipped' => $skipped === [] ? null : implode(',', $skipped),
        ]));
    }
}

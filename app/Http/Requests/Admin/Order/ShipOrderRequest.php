<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use App\Domain\Ordering\Actions\MarkOrderShipped;
use App\Domain\Ordering\Models\Order;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /orders/{order}/ship` — manual dispatch (P5, until Phase 6 courier booking).
 * Needs orders.update + shipments.book. Both courier fields are free text and optional:
 * a self-delivery has neither.
 */
final class ShipOrderRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof Order && ($this->user()?->can('fulfil', $order) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Courier the parcel was handed to, booked outside the system (e.g. "Pathao", "own rider"). */
            'courier_name' => ['nullable', 'string', 'max:'.MarkOrderShipped::FIELD_MAX],
            /** The courier's consignment or tracking reference, as printed on its receipt. */
            'tracking_reference' => ['nullable', 'string', 'max:'.MarkOrderShipped::FIELD_MAX],
            /** Shown on the order's activity timeline next to the change. */
            'note' => ['nullable', 'string', 'max:'.OrderWorkflowRequest::NOTE_MAX],
        ];
    }

    public function courierName(): ?string
    {
        return self::text($this->input('courier_name'));
    }

    public function trackingReference(): ?string
    {
        return self::text($this->input('tracking_reference'));
    }

    public function note(): ?string
    {
        return self::text($this->input('note'));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

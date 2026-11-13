<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Shipment;

use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Illuminate\Validation\Rule;

/**
 * `GET /shipments/{shipment}/label` and `POST /shipments/labels` — shipping label PDFs.
 * Needs shipments.view AND customers.view_pii: the label carries the full phone and street
 * address the courier needs (rule 20, like the packing slip). `locale` picks the label's
 * printed captions (default English; the address is printed as the customer typed it).
 */
final class ShipmentLabelsRequest extends ApiFormRequest
{
    public const int MAX_SHIPMENTS = 100;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can(Permission::ShipmentsView->value) && $user->can(Permission::CustomersViewPii->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shipment_ids' => [$this->route('shipment') === null ? 'required' : 'prohibited', 'array', 'min:1', 'max:'.self::MAX_SHIPMENTS],
            'shipment_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'locale' => ['nullable', 'string', Rule::in(['en', 'bn'])],
        ];
    }

    /** @return list<int> */
    public function shipmentIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = array_values((array) $this->input('shipment_ids', []));

        return array_map(intval(...), $ids);
    }

    public function labelLocale(): string
    {
        return $this->input('locale') === 'bn' ? 'bn' : 'en';
    }
}

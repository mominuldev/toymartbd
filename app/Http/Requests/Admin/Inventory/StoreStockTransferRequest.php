<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inventory;

use App\Domain\Inventory\DTOs\StockTransferData;
use App\Domain\Inventory\Models\StockTransfer;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /inventory/transfers` — needs inventory.transfer and an `Idempotency-Key` (a
 * double-submitted transfer would move the stock twice). Each line names a variant and
 * the units to move (> 0). That the source has them AVAILABLE (on hand minus reserved)
 * is checked by TransferStock against the locked rows, never here.
 */
final class StoreStockTransferRequest extends ApiFormRequest
{
    public const int MAX_LINES = 100;

    public const int MAX_UNITS = 100000;

    public function authorize(): bool
    {
        return $this->user()?->can('transfer', StockTransfer::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $active = Rule::exists('stock_locations', 'id')->where('is_active', true);

        return [
            /** Where the units leave from. */
            'from_location_id' => ['required', 'integer', $active],
            /** Where they arrive; must differ from the source. */
            'to_location_id' => ['required', 'integer', 'different:from_location_id', $active],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            /** Units to move, > 0. */
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_UNITS],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to_location_id.different' => __('inventory.transfer.same_location'),
            'lines.*.variant_id.distinct' => __('inventory.transfer.duplicate_variant'),
        ];
    }

    public function toData(): StockTransferData
    {
        /** @var list<array{variant_id: int|string, quantity: int|string}> $lines */
        $lines = array_values((array) $this->input('lines', []));
        $note = $this->input('note');
        $quantities = [];
        foreach ($lines as $line) {
            $quantities[(int) $line['variant_id']] = (int) $line['quantity'];
        }

        return new StockTransferData(
            fromLocationId: $this->integer('from_location_id'),
            toLocationId: $this->integer('to_location_id'),
            lines: $quantities,
            note: is_string($note) && trim($note) !== '' ? trim($note) : null,
        );
    }
}

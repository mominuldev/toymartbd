<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inventory;

use App\Domain\Inventory\DTOs\StockAdjustmentData;
use App\Domain\Inventory\DTOs\StockAdjustmentLine;
use App\Domain\Inventory\Enums\StockAdjustmentReason;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /inventory/adjustments` — needs inventory.adjust and an `Idempotency-Key`
 * (a double-submitted adjustment would move the stock twice).
 *
 * Each line names a variant and EITHER `quantity` (a signed change, never 0: -2 for two
 * damaged units) OR `counted` (what a stock count found; the server works out the
 * change under the row lock). Which way a reason may move stock, and that on hand never
 * drops below what is reserved, is checked by AdjustStock against the locked row.
 */
final class StoreStockAdjustmentRequest extends ApiFormRequest
{
    public const int MAX_LINES = 100;

    public const int MAX_UNITS = 100000;

    public function authorize(): bool
    {
        return $this->user()?->can('adjust', StockAdjustment::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The stock location; the default location when omitted. */
            'location_id' => ['sometimes', 'nullable', 'integer', Rule::exists('stock_locations', 'id')->where('is_active', true)],
            'reason' => ['required', Rule::enum(StockAdjustmentReason::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            /** Signed change in units (e.g. -2). Omit when sending `counted`. */
            'lines.*.quantity' => ['nullable', 'integer', 'not_in:0', 'min:-'.self::MAX_UNITS, 'max:'.self::MAX_UNITS, 'required_without:lines.*.counted', 'prohibits:lines.*.counted'],
            /** The on-hand quantity a stock count found. Omit when sending `quantity`. */
            'lines.*.counted' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_UNITS, 'required_without:lines.*.quantity'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.*.variant_id.distinct' => __('inventory.adjust.duplicate_variant'),
            'lines.*.quantity.prohibits' => __('inventory.adjust.quantity_or_counted'),
            'lines.*.quantity.not_in' => __('inventory.adjust.no_change'),
        ];
    }

    public function toData(): StockAdjustmentData
    {
        /** @var list<array{variant_id: int|string, quantity?: int|string|null, counted?: int|string|null}> $lines */
        $lines = array_values((array) $this->input('lines', []));
        $note = $this->input('note');
        $location = $this->input('location_id');

        return new StockAdjustmentData(
            reason: StockAdjustmentReason::from($this->string('reason')->toString()),
            lines: array_map(static fn (array $line): StockAdjustmentLine => new StockAdjustmentLine(
                (int) $line['variant_id'],
                isset($line['quantity']) ? (int) $line['quantity'] : null,
                isset($line['counted']) ? (int) $line['counted'] : null,
            ), $lines),
            locationId: is_numeric($location) ? (int) $location : null,
            note: is_string($note) && trim($note) !== '' ? trim($note) : null,
        );
    }
}

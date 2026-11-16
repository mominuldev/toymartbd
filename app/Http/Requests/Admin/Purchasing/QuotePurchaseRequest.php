<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Purchasing;

use App\Domain\Purchasing\DTOs\PurchaseLineData;
use App\Domain\Purchasing\Models\Purchase;
use App\Http\Requests\ApiFormRequest;
use App\Support\Money;
use Illuminate\Validation\Rule;

/**
 * `POST /purchases/quote` — the New / edit purchase order form's lines, priced by the
 * server before anything is saved (rule 1). Same line rules as the PO itself; the supplier
 * is optional and only adds "last bought at". Needs purchasing.create.
 */
final class QuotePurchaseRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Purchase::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Adds each line's `last_unit_cost` from this supplier's earlier purchase orders. */
            'supplier_id' => ['sometimes', 'nullable', 'integer', Rule::exists('suppliers', 'id')],
            'lines' => ['required', 'array', 'min:1', 'max:'.PurchaseDocumentRequest::MAX_LINES],
            'lines.*.variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.PurchaseDocumentRequest::MAX_UNITS],
            /** The supplier's price per unit, up to 4 decimals. */
            'lines.*.unit_cost' => ['required', 'string', 'regex:'.PurchaseDocumentRequest::UNIT_COST_PATTERN],
            /** Discount on the whole line, in taka. */
            'lines.*.discount' => ['nullable', 'string', 'regex:'.PurchaseDocumentRequest::MONEY_PATTERN],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['lines.*.variant_id.distinct' => __('purchasing.lines.duplicate_variant')];
    }

    public function supplierId(): ?int
    {
        $id = $this->input('supplier_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /** @return list<PurchaseLineData> */
    public function lines(): array
    {
        /** @var list<array{variant_id: int|string, quantity: int|string, unit_cost: string, discount?: string|null}> $lines */
        $lines = array_values((array) $this->input('lines', []));

        return array_map(static fn (array $line): PurchaseLineData => new PurchaseLineData(
            (int) $line['variant_id'],
            (int) $line['quantity'],
            bcadd($line['unit_cost'], '0', 4),
            isset($line['discount']) && $line['discount'] !== '' ? Money::of($line['discount']) : Money::zero(),
        ), $lines);
    }
}

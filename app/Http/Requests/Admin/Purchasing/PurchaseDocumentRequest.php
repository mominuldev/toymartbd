<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Purchasing;

use App\Domain\Purchasing\DTOs\PurchaseData;
use App\Domain\Purchasing\DTOs\PurchaseLineData;
use App\Domain\Purchasing\Enums\SupplierStatus;
use App\Http\Requests\ApiFormRequest;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * A PO document: header + lines. Line totals and the PO total are computed by the
 * server (rule 1); the client sends only quantities, unit costs and discounts.
 */
abstract class PurchaseDocumentRequest extends ApiFormRequest
{
    public const int MAX_LINES = 200;

    public const int MAX_UNITS = 1_000_000;

    /** Unit costs carry up to 4 decimals (DECIMAL(14,4)). */
    public const string UNIT_COST_PATTERN = '/^\d{1,10}(\.\d{1,4})?$/';

    public const string MONEY_PATTERN = '/^\d{1,12}(\.\d{1,2})?$/';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('status', SupplierStatus::Active->value)],
            /** The stock location the goods are expected at; the default location when omitted. */
            'location_id' => ['sometimes', 'nullable', 'integer', Rule::exists('stock_locations', 'id')->where('is_active', true)],
            /** Y-m-d (Dhaka). */
            'order_date' => ['required', 'date_format:Y-m-d'],
            'expected_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_UNITS],
            /** The supplier's price per unit, up to 4 decimals. */
            'lines.*.unit_cost' => ['required', 'string', 'regex:'.self::UNIT_COST_PATTERN],
            /** Discount on the whole line, in taka. */
            'lines.*.discount' => ['nullable', 'string', 'regex:'.self::MONEY_PATTERN],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['lines.*.variant_id.distinct' => __('purchasing.lines.duplicate_variant')];
    }

    public function toData(): PurchaseData
    {
        /** @var list<array{variant_id: int|string, quantity: int|string, unit_cost: string, discount?: string|null}> $lines */
        $lines = array_values((array) $this->input('lines', []));
        $expected = $this->input('expected_date');
        $location = $this->input('location_id');
        $invoice = $this->input('supplier_invoice_no');
        $note = $this->input('note');

        return new PurchaseData(
            supplierId: $this->integer('supplier_id'),
            lines: array_map(static fn (array $line): PurchaseLineData => new PurchaseLineData(
                (int) $line['variant_id'],
                (int) $line['quantity'],
                bcadd($line['unit_cost'], '0', 4),
                isset($line['discount']) && $line['discount'] !== '' ? Money::of($line['discount']) : Money::zero(),
            ), $lines),
            orderDate: CarbonImmutable::createFromFormat('!Y-m-d', $this->string('order_date')->toString()) ?: CarbonImmutable::now(),
            expectedDate: is_string($expected) && $expected !== '' ? (CarbonImmutable::createFromFormat('!Y-m-d', $expected) ?: null) : null,
            locationId: is_numeric($location) ? (int) $location : null,
            supplierInvoiceNo: is_string($invoice) && trim($invoice) !== '' ? trim($invoice) : null,
            note: is_string($note) && trim($note) !== '' ? trim($note) : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Purchasing;

use App\Domain\Finance\Enums\ExpensePaymentMethod;
use App\Domain\Purchasing\DTOs\ReceiptData;
use App\Domain\Purchasing\Enums\LandedCostMethod;
use App\Domain\Purchasing\Models\Purchase;
use App\Http\Requests\ApiFormRequest;
use App\Support\Money;
use Illuminate\Validation\Rule;

/**
 * `POST /purchases/{purchase}/receipts` and `…/receipts/quote` — the units arriving per
 * PO line, where they go, and the landed costs to split across them. The quote takes
 * the same body and returns every figure the receipt would write; needs
 * purchasing.receive. Over-receipt and unknown lines are 422s from ReceiptCalculator.
 */
final class ReceivePurchaseRequest extends ApiFormRequest
{
    public const string MONEY_PATTERN = '/^\d{1,12}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        $purchase = $this->route('purchase');

        return $purchase instanceof Purchase && ($this->user()?->can('receive', $purchase) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['nullable', 'string', 'regex:'.self::MONEY_PATTERN];

        return [
            /** Where the goods are put away; the PO's location when omitted. */
            'location_id' => ['sometimes', 'nullable', 'integer', Rule::exists('stock_locations', 'id')->where('is_active', true)],
            'lines' => ['required', 'array', 'min:1', 'max:'.PurchaseDocumentRequest::MAX_LINES],
            'lines.*.purchase_item_id' => ['required', 'integer'],
            /** Units arriving now; 0 skips the line. */
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:'.PurchaseDocumentRequest::MAX_UNITS],
            /** Inbound freight for this delivery, in taka. */
            'freight' => $money,
            /** Customs and import duty for this delivery, in taka. */
            'customs_duty' => $money,
            /** Any other landed cost (labour, loading, insurance), in taka. */
            'other_cost' => $money,
            /** How the landed costs are split across lines; by value when omitted. */
            'apportion_method' => ['sometimes', Rule::enum(LandedCostMethod::class)],
            /** Whether the supplier charged the landed costs on its invoice (they add to what we owe it). Default true. */
            'costs_paid_to_supplier' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
            /** Who charged the landed costs when not the supplier (freight forwarder, C&F agent). Only with `costs_paid_to_supplier: false`. */
            'costs_vendor' => ['nullable', 'string', 'max:160'],
            /** Their bill or transaction reference. */
            'costs_reference' => ['nullable', 'string', 'max:120'],
            /** How they were paid, if already paid; omit when the bill is still open. */
            'costs_payment_method' => ['nullable', Rule::enum(ExpensePaymentMethod::class)],
        ];
    }

    public function toData(): ReceiptData
    {
        /** @var list<array{purchase_item_id: int|string, quantity: int|string}> $lines */
        $lines = array_values((array) $this->input('lines', []));
        $location = $this->input('location_id');
        $note = $this->input('note');

        return new ReceiptData(
            lines: array_map(static fn (array $line): array => [
                'purchase_item_id' => (int) $line['purchase_item_id'],
                'quantity' => (int) $line['quantity'],
            ], $lines),
            freight: self::money($this->input('freight')),
            customsDuty: self::money($this->input('customs_duty')),
            otherCost: self::money($this->input('other_cost')),
            method: $this->has('apportion_method') ? LandedCostMethod::from($this->string('apportion_method')->toString()) : LandedCostMethod::Value,
            locationId: is_numeric($location) ? (int) $location : null,
            costsPaidToSupplier: $this->has('costs_paid_to_supplier') ? $this->boolean('costs_paid_to_supplier') : true,
            note: is_string($note) && trim($note) !== '' ? trim($note) : null,
            costsVendor: self::text($this->input('costs_vendor')),
            costsReference: self::text($this->input('costs_reference')),
            costsPaymentMethod: self::text($this->input('costs_payment_method')),
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function money(mixed $value): Money
    {
        return is_string($value) && $value !== '' ? Money::of($value) : Money::zero();
    }
}

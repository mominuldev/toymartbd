<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Purchasing;

use App\Domain\Purchasing\DTOs\SupplierPaymentData;
use App\Domain\Purchasing\Enums\SupplierPaymentMethod;
use App\Domain\Purchasing\Models\SupplierPayment;
use App\Http\Requests\ApiFormRequest;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * `POST /suppliers/{supplier}/payments` — needs finance.record_supplier_payments and an
 * `Idempotency-Key` (a double-submitted payment would pay twice on the books).
 */
final class StoreSupplierPaymentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SupplierPayment::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Taka, more than zero. */
            'amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'method' => ['required', Rule::enum(SupplierPaymentMethod::class)],
            /** Bank/bKash transaction id, cheque number … */
            'reference' => ['nullable', 'string', 'max:120'],
            /** The Dhaka date the money left, Y-m-d; never in the future. */
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.DhakaDate::today()],
            /** The PO this pays against, if any (must belong to this supplier). */
            'purchase_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function toData(): SupplierPaymentData
    {
        $purchase = $this->input('purchase_id');
        $reference = $this->input('reference');
        $note = $this->input('note');

        return new SupplierPaymentData(
            amount: Money::of($this->string('amount')->toString()),
            method: SupplierPaymentMethod::from($this->string('method')->toString()),
            paidAt: CarbonImmutable::createFromFormat('!Y-m-d', $this->string('paid_at')->toString()) ?: CarbonImmutable::now(),
            purchaseId: is_numeric($purchase) ? (int) $purchase : null,
            reference: is_string($reference) && trim($reference) !== '' ? trim($reference) : null,
            note: is_string($note) && trim($note) !== '' ? trim($note) : null,
        );
    }
}

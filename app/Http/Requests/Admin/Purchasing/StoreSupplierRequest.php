<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Purchasing;

use App\Domain\Purchasing\DTOs\SupplierData;
use App\Domain\Purchasing\Models\Supplier;
use App\Http\Requests\ApiFormRequest;
use App\Support\Money;

/** `POST /suppliers` — needs purchasing.manage_suppliers. */
final class StoreSupplierRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Supplier::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = SupplierRules::fields();
        $rules['name'] = ['required', ...$rules['name']];
        $rules['payment_terms_days'] = ['sometimes', ...$rules['payment_terms_days']];
        $rules['opening_balance'] = ['sometimes', ...$rules['opening_balance']];

        return $rules;
    }

    public function toData(): SupplierData
    {
        $district = $this->input('district_id');
        $opening = $this->input('opening_balance');

        return new SupplierData(
            name: trim($this->string('name')->toString()),
            contactPerson: SupplierRules::text($this->input('contact_person')),
            phone: SupplierRules::phone($this->input('phone')),
            altPhone: SupplierRules::phone($this->input('alt_phone')),
            email: SupplierRules::text($this->input('email')),
            address: SupplierRules::text($this->input('address')),
            districtId: is_numeric($district) ? (int) $district : null,
            tin: SupplierRules::text($this->input('tin')),
            paymentTermsDays: $this->integer('payment_terms_days'),
            openingBalance: is_string($opening) ? Money::of($opening) : Money::zero(),
            note: SupplierRules::text($this->input('note')),
        );
    }
}

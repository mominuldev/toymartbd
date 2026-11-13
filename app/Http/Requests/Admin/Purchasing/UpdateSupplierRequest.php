<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Purchasing;

use App\Domain\Purchasing\Enums\SupplierStatus;
use App\Domain\Purchasing\Models\Supplier;
use App\Http\Requests\ApiFormRequest;
use App\Support\Money;
use Illuminate\Validation\Rule;

/**
 * `PATCH /suppliers/{supplier}` — every field optional; only the fields sent change.
 * `status: active` restores an archived supplier. Needs purchasing.manage_suppliers.
 */
final class UpdateSupplierRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $supplier = $this->route('supplier');

        return $supplier instanceof Supplier && ($this->user()?->can('update', $supplier) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [];
        foreach (SupplierRules::fields() as $field => $fieldRules) {
            $rules[$field] = ['sometimes', ...($field === 'name' ? ['required'] : []), ...$fieldRules];
        }
        $rules['status'] = ['sometimes', Rule::enum(SupplierStatus::class)];

        return $rules;
    }

    /** @return array<string, mixed> */
    public function changes(): array
    {
        $changes = [];
        foreach (['contact_person', 'email', 'address', 'tin', 'note'] as $field) {
            if ($this->has($field)) {
                $changes[$field] = SupplierRules::text($this->input($field));
            }
        }
        foreach (['phone', 'alt_phone'] as $field) {
            if ($this->has($field)) {
                $changes[$field] = SupplierRules::phone($this->input($field));
            }
        }
        if ($this->has('name')) {
            $changes['name'] = trim($this->string('name')->toString());
        }
        if ($this->has('district_id')) {
            $district = $this->input('district_id');
            $changes['district_id'] = is_numeric($district) ? (int) $district : null;
        }
        if ($this->has('payment_terms_days')) {
            $changes['payment_terms_days'] = $this->integer('payment_terms_days');
        }
        if ($this->has('opening_balance')) {
            $changes['opening_balance'] = Money::of($this->string('opening_balance')->toString());
        }
        if ($this->has('status')) {
            $changes['status'] = $this->string('status')->toString();
        }

        return $changes;
    }
}

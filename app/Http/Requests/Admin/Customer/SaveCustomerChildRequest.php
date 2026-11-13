<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Customer;

use App\Domain\Customer\Models\Customer;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\ChildRules;

/**
 * `POST /customers/{customer}/children` and `PATCH …/children/{child}` (P8 batch 3) — staff add
 * or edit a child as the parent told them. Children's data: customers.update AND
 * customers.view_pii (rule 20). PATCH replaces every field.
 */
final class SaveCustomerChildRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer && ($this->user()?->can('manageChildren', $customer) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ChildRules::rules();
    }

    /** @return array{name: string|null, month: int, day: int, year: int|null} */
    public function child(): array
    {
        return ChildRules::values($this);
    }
}

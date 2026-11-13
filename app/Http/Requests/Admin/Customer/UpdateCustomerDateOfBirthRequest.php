<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Customer;

use App\Domain\Customer\Models\Customer;
use App\Http\Requests\ApiFormRequest;

/**
 * `PUT /customers/{customer}/date-of-birth` — needs customers.update AND customers.view_pii:
 * a birth date is personal data, so only staff who may see it may change it (rule 20).
 */
final class UpdateCustomerDateOfBirthRequest extends ApiFormRequest
{
    public const string EARLIEST = '1900-01-01';

    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer && ($this->user()?->can('updateDateOfBirth', $customer) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Y-m-d, in the past; null clears it. */
            'date_of_birth' => ['present', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.self::EARLIEST, 'before:today'],
        ];
    }

    public function dateOfBirth(): ?string
    {
        return $this->filled('date_of_birth') ? $this->string('date_of_birth')->toString() : null;
    }
}

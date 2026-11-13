<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Customer;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerNote;
use App\Http\Requests\ApiFormRequest;

/** `POST /customers/{customer}/notes` — needs customers.update. Notes are append-only. */
final class StoreCustomerNoteRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer && ($this->user()?->can('addNote', $customer) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.CustomerNote::MAX_LENGTH],
            /** Pinned notes show on the profile's summary rail. */
            'is_pinned' => ['sometimes', 'boolean'],
        ];
    }
}

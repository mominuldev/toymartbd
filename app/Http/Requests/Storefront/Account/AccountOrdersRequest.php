<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use App\Domain\Ordering\Queries\CustomerOrders;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `GET /storefront/account/orders` — newest first, cursor-paginated. One allowlisted
 * filter, `group` (rule 16): anything else in the query string is refused.
 */
final class AccountOrdersRequest extends AccountRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Opaque cursor from `meta.next_cursor`. */
            'cursor' => ['nullable', 'string', 'max:512'],
            /** @var 'active'|'delivered'|'cancelled'|'returned' */
            'group' => ['nullable', 'string', Rule::in(array_keys(CustomerOrders::GROUPS))],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query()), ['cursor', 'group', 'locale']) as $unknown) {
                $validator->errors()->add((string) $unknown, (string) __('validation.prohibited', ['attribute' => (string) $unknown]));
            }
        }];
    }

    public function group(): ?string
    {
        return $this->filled('group') ? $this->string('group')->toString() : null;
    }

    public function cursor(): ?string
    {
        return $this->filled('cursor') ? $this->string('cursor')->toString() : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use App\Domain\Ordering\Queries\CustomerOrders;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * `GET /storefront/account/orders` — newest first, cursor-paginated. One allowlisted
 * filter, `group` (rule 16): an unlisted query parameter or an unknown group is a 400
 * `bad-request`, the same as the catalog listing and every admin list.
 */
final class AccountOrdersRequest extends AccountRequest
{
    /** Query parameters this endpoint accepts (`locale` is ResolveLocale's). */
    public const array PARAMETERS = ['cursor', 'group', 'locale'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Opaque cursor from `meta.next_cursor`. */
            'cursor' => ['nullable', 'string', 'max:512'],
            /** @var 'active'|'delivered'|'cancelled'|'returned' */
            'group' => ['nullable', 'string'],
        ];
    }

    /** @throws BadRequestHttpException */
    protected function prepareForValidation(): void
    {
        $unknown = array_diff(array_map(strval(...), array_keys($this->query())), self::PARAMETERS);

        if ($unknown !== []) {
            throw new BadRequestHttpException('Unknown query parameter(s): '.implode(', ', $unknown).'. Allowed: cursor, group.');
        }

        $group = $this->query('group');

        if ($group !== null && $group !== '' && (! is_string($group) || ! array_key_exists($group, CustomerOrders::GROUPS))) {
            throw new BadRequestHttpException('group must be one of: '.implode(', ', array_keys(CustomerOrders::GROUPS)).'.');
        }
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

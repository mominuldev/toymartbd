<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use App\Domain\Customer\Models\Customer;
use App\Http\Requests\ApiFormRequest;
use LogicException;

/**
 * Base for `/storefront/account/*` (P4-STO-11): the `customer` guard has authenticated a
 * Customer by their Sanctum token. Every query a subclass runs is scoped to this customer,
 * so another customer's rows are simply not found.
 */
abstract class AccountRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user('customer') instanceof Customer;
    }

    public function customer(): Customer
    {
        $customer = $this->user('customer');

        return $customer instanceof Customer ? $customer : throw new LogicException('No customer on an account route.');
    }
}

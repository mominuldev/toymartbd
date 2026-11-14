<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pos;

use App\Domain\Pos\Models\PosSession;
use App\Http\Requests\ApiFormRequest;
use App\Support\PhoneNumber;
use Illuminate\Validation\Validator;

/** `GET /pos/customers?phone=` — who the number the cashier just typed belongs to. Needs pos.use. */
final class PosCustomerLookupRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', PosSession::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** As typed: `01819 552 310`, `+8801819552310`… (rule 30). */
            'phone' => ['required', 'string', 'max:32'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->has('phone') && ! PhoneNumber::isValid($this->string('phone')->toString())) {
                $validator->errors()->add('phone', (string) __('checkout.phone.invalid'));
            }
        }];
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::parse($this->string('phone')->toString());
    }
}

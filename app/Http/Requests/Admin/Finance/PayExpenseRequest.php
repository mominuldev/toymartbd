<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance;

use App\Domain\Finance\Enums\ExpensePaymentMethod;
use App\Domain\Finance\Models\Expense;
use App\Http\Requests\ApiFormRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/** `POST /finance/expenses/{expense}/pay` — `payment_method` and `paid_on` (a Dhaka date, not in the future). */
final class PayExpenseRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $expense = $this->route('expense');

        return $expense instanceof Expense && ($this->user()?->can('pay', $expense) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::enum(ExpensePaymentMethod::class)],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before:'.CarbonImmutable::now('Asia/Dhaka')->addDay()->toDateString()],
        ];
    }

    public function paymentMethod(): ExpensePaymentMethod
    {
        return ExpensePaymentMethod::from((string) $this->validated('payment_method'));
    }

    public function paidOn(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->validated('paid_on'), 'Asia/Dhaka') ?? CarbonImmutable::now('Asia/Dhaka');
    }
}

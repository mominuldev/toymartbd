<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance;

use App\Domain\Finance\Models\Expense;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /finance/expenses/{expense}/approve|reject|void`. Reject and void need a `reason`
 * (kept on the expense); approve takes none. The ability checked is the route's action.
 */
final class ExpenseDecisionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $expense = $this->route('expense');

        return $expense instanceof Expense && ($this->user()?->can($this->ability(), $expense) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->ability() === 'approve'
            ? ['reason' => ['prohibited']]
            : ['reason' => ['required', 'string', 'min:3', 'max:500']];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason', ''));
    }

    private function ability(): string
    {
        $name = (string) $this->route()?->getName();

        return match (true) {
            str_ends_with($name, '.reject') => 'reject',
            str_ends_with($name, '.void') => 'void',
            default => 'approve',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance;

use App\Domain\Finance\DTOs\ExpenseData;
use App\Domain\Finance\DTOs\UploadedReceipt;
use App\Domain\Finance\Enums\ExpensePaymentMethod;
use App\Domain\Finance\Enums\ExpenseRecurrence;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Services\ExpenseAttachments;
use App\Http\Requests\ApiFormRequest;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The expense document, for `POST /finance/expenses` (JSON or `multipart/form-data` with an
 * optional `receipt` file) and `PATCH /finance/expenses/{expense}` (JSON; the receipt has its
 * own endpoint). Money is a decimal string > 0. Dates are Dhaka dates (YYYY-MM-DD) and an
 * expense cannot be dated in the future. A null `payment_method` means "not paid yet".
 */
final class ExpenseRequest extends ApiFormRequest
{
    public const string MONEY_PATTERN = '/^\d{1,12}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        $expense = $this->expense();

        return $expense === null
            ? ($this->user()?->can('create', Expense::class) ?? false)
            : ($this->user()?->can('update', $expense) ?? false);
    }

    protected function prepareForValidation(): void
    {
        // multipart sends booleans and nulls as strings.
        foreach (['is_recurring'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => filter_var($this->input($key), FILTER_VALIDATE_BOOL)]);
            }
        }
        foreach (['payment_method', 'paid_on', 'recurrence', 'order_id', 'shipment_id', 'reference', 'vendor', 'description'] as $key) {
            if ($this->input($key) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tomorrow = CarbonImmutable::now('Asia/Dhaka')->addDay()->toDateString();

        return [
            'category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')],
            'amount' => ['required', 'string', 'regex:'.self::MONEY_PATTERN, 'not_regex:/^0+(\.0+)?$/'],
            'expense_date' => ['required', 'date_format:Y-m-d', 'before:'.$tomorrow],
            'payment_method' => ['nullable', Rule::enum(ExpensePaymentMethod::class)],
            'paid_on' => ['nullable', 'date_format:Y-m-d', 'before:'.$tomorrow],
            'reference' => ['nullable', 'string', 'max:120'],
            'vendor' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_recurring' => ['sometimes', 'boolean'],
            'recurrence' => ['nullable', Rule::enum(ExpenseRecurrence::class), 'required_if:is_recurring,true'],
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')],
            'shipment_id' => ['nullable', 'integer', 'min:1'],
            'receipt' => ['nullable', 'file', 'max:10240', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value instanceof UploadedFile || ! $value->isValid()) {
                    $fail('The upload failed; try again.');

                    return;
                }
                try {
                    app(ExpenseAttachments::class)->inspect($value->getRealPath() ?: $value->getPathname());
                } catch (InvalidArgumentException $e) {
                    $fail($e->getMessage());
                }
            }],
        ];
    }

    public function toData(): ExpenseData
    {
        $paidOn = $this->validated('paid_on');
        $method = $this->validated('payment_method');
        $recurrence = $this->validated('recurrence');

        return new ExpenseData(
            categoryId: (int) $this->validated('category_id'),
            amount: Money::of((string) $this->validated('amount')),
            expenseDate: CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->validated('expense_date'), 'Asia/Dhaka') ?? CarbonImmutable::now('Asia/Dhaka'),
            paymentMethod: is_string($method) ? ExpensePaymentMethod::from($method) : null,
            paidOn: is_string($paidOn) ? CarbonImmutable::createFromFormat('!Y-m-d', $paidOn, 'Asia/Dhaka') : null,
            reference: self::text($this->validated('reference')),
            vendor: self::text($this->validated('vendor')),
            description: self::text($this->validated('description')),
            isRecurring: (bool) $this->validated('is_recurring', false),
            recurrence: is_string($recurrence) ? ExpenseRecurrence::from($recurrence) : null,
            orderId: is_numeric($id = $this->validated('order_id')) ? (int) $id : null,
            shipmentId: is_numeric($sid = $this->validated('shipment_id')) ? (int) $sid : null,
        );
    }

    public function receipt(): ?UploadedReceipt
    {
        $file = $this->file('receipt');

        return $file instanceof UploadedFile
            ? new UploadedReceipt($file->getRealPath() ?: $file->getPathname(), $file->getClientOriginalName())
            : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function expense(): ?Expense
    {
        $expense = $this->route('expense');

        return $expense instanceof Expense ? $expense : null;
    }
}

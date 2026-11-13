<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance;

use App\Domain\Finance\DTOs\UploadedReceipt;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Services\ExpenseAttachments;
use App\Http\Requests\ApiFormRequest;
use Closure;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/** `POST /finance/expenses/{expense}/receipt` — multipart `receipt`: a PDF, JPEG, PNG or WebP ≤ 10 MB (by content). */
final class ExpenseReceiptRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $expense = $this->route('expense');

        return $expense instanceof Expense && ($this->user()?->can('update', $expense) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'receipt' => ['required', 'file', 'max:10240', static function (string $attribute, mixed $value, Closure $fail): void {
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

    public function receipt(): UploadedReceipt
    {
        $file = $this->file('receipt');
        assert($file instanceof UploadedFile);

        return new UploadedReceipt($file->getRealPath() ?: $file->getPathname(), $file->getClientOriginalName());
    }
}

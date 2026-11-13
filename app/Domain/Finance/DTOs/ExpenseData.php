<?php

declare(strict_types=1);

namespace App\Domain\Finance\DTOs;

use App\Domain\Finance\Enums\ExpensePaymentMethod;
use App\Domain\Finance\Enums\ExpenseRecurrence;
use App\Domain\Finance\Models\Expense;
use App\Support\Money;
use Carbon\CarbonImmutable;

/** The editable fields of an expense, validated by the FormRequest. */
final readonly class ExpenseData
{
    public function __construct(
        public int $categoryId,
        public Money $amount,
        public CarbonImmutable $expenseDate,
        public ?ExpensePaymentMethod $paymentMethod,
        public ?CarbonImmutable $paidOn,
        public ?string $reference,
        public ?string $vendor,
        public ?string $description,
        public bool $isRecurring,
        public ?ExpenseRecurrence $recurrence,
        public ?int $orderId,
        public ?int $shipmentId,
    ) {}

    /** The expense's current fields (to change only its receipt). */
    public static function fromExpense(Expense $e): self
    {
        return new self(
            categoryId: $e->category_id,
            amount: $e->amount,
            expenseDate: CarbonImmutable::instance($e->expense_date),
            paymentMethod: $e->payment_method,
            paidOn: $e->paid_on === null ? null : CarbonImmutable::instance($e->paid_on),
            reference: $e->reference,
            vendor: $e->vendor,
            description: $e->description,
            isRecurring: $e->is_recurring,
            recurrence: $e->recurrence,
            orderId: $e->order_id,
            shipmentId: $e->shipment_id,
        );
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'category_id' => $this->categoryId,
            'amount' => $this->amount,
            'expense_date' => $this->expenseDate->toDateString(),
            'payment_method' => $this->paymentMethod?->value,
            'paid_on' => $this->paymentMethod === null ? null : ($this->paidOn ?? $this->expenseDate)->toDateString(),
            'reference' => $this->reference,
            'vendor' => $this->vendor,
            'description' => $this->description,
            'is_recurring' => $this->isRecurring,
            'recurrence' => $this->isRecurring ? ($this->recurrence ?? ExpenseRecurrence::Monthly)->value : null,
            'order_id' => $this->orderId,
            'shipment_id' => $this->shipmentId,
        ];
    }
}

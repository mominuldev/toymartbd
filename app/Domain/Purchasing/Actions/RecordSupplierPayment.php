<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\DTOs\SupplierPaymentData;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Events\SupplierPaymentRecorded;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierPayment;
use App\Domain\Purchasing\Services\PurchaseTotals;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records money paid to a supplier (PRD N-10), optionally against one PO, whose
 * paid/due amounts move in the same transaction. A payment may exceed the balance:
 * that is an advance, shown as a negative balance.
 *
 * The ledger entry (`supplier_payment`: cash out, not revenue — rule 10) is written by
 * Finance from SupplierPaymentRecorded after commit (rule 2): Purchasing never touches
 * `financial_transactions`. Buying the stock itself never hit the P&L; this is the
 * cash side only.
 */
final readonly class RecordSupplierPayment
{
    public function handle(Supplier $supplier, SupplierPaymentData $data, ?int $userId = null): SupplierPayment
    {
        return DB::transaction(static function () use ($supplier, $data, $userId): SupplierPayment {
            if (! $data->amount->greaterThan(Money::zero())) {
                throw ValidationException::withMessages(['amount' => __('purchasing.payment.positive')]);
            }

            if ($data->purchaseId !== null) {
                $purchase = Purchase::query()->lockForUpdate()->find($data->purchaseId);
                if ($purchase === null || $purchase->supplier_id !== $supplier->id) {
                    throw ValidationException::withMessages(['purchase_id' => __('purchasing.payment.other_supplier')]);
                }
                if (in_array($purchase->status, [PurchaseStatus::Draft, PurchaseStatus::Cancelled], true)) {
                    throw ValidationException::withMessages(['purchase_id' => __('purchasing.payment.not_payable', ['status' => $purchase->status->value])]);
                }
                $purchase->paid_amount = $purchase->paid_amount->plus($data->amount);
                PurchaseTotals::refresh($purchase);
                $purchase->save();
            }

            $payment = SupplierPayment::query()->create([
                'supplier_id' => $supplier->id,
                'purchase_id' => $data->purchaseId,
                'amount' => $data->amount,
                'method' => $data->method,
                'reference' => $data->reference,
                'paid_at' => $data->paidAt->toDateString(),
                'note' => $data->note,
                'created_by' => $userId,
            ]);

            SupplierPaymentRecorded::dispatch(
                $payment->id,
                $supplier->id,
                $data->purchaseId,
                $data->amount->toDecimal(),
                $data->paidAt->toDateString(),
                $data->method->value,
                $supplier->name,
            );

            return $payment;
        });
    }
}

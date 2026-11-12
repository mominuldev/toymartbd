<?php

declare(strict_types=1);

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\PaymentViolation;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One payment attempt against an order (DATABASE-DESIGN §3.5, P4-PAY-01). Status moves
 * only through {@see \App\Domain\Payment\Services\PaymentTransitions}; a payment is never
 * deleted (it is money history). The order relation is declared on the Order side —
 * Payment does not depend on Ordering (deptrac).
 *
 * @property int $id
 * @property int $order_id
 * @property PaymentMethod $method
 * @property string|null $gateway
 * @property Money $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $transaction_id
 * @property string|null $gateway_reference
 * @property array<string, mixed>|null $gateway_payload
 * @property Money $fee_amount
 * @property Money $net_amount
 * @property Carbon|null $paid_at
 * @property string|null $failure_reason
 * @property int|null $collected_by
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'order_id', 'method', 'gateway', 'amount', 'currency', 'status', 'transaction_id',
        'gateway_reference', 'gateway_payload', 'fee_amount', 'net_amount', 'paid_at',
        'failure_reason', 'collected_by', 'verified_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'currency' => 'BDT',
        'fee_amount' => '0.00',
        'net_amount' => '0.00',
    ];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw PaymentViolation::neverDeleted());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'method' => PaymentMethod::class,
            'amount' => MoneyCast::class,
            'status' => PaymentStatus::class,
            'gateway_payload' => 'array',
            'fee_amount' => MoneyCast::class,
            'net_amount' => MoneyCast::class,
            'paid_at' => 'datetime',
            'collected_by' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }
}

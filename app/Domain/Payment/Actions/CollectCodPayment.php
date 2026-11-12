<?php

declare(strict_types=1);

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Gateways\CashOnDeliveryGateway;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Services\PaymentTransitions;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The courier collected a COD payment on delivery (§4.3): pending → paid through the same
 * PaymentTransitions::apply an online verification uses. A short amount is refused, not
 * recorded as paid (rule 19) — partial collection is a staff reconciliation.
 */
final readonly class CollectCodPayment
{
    public function __construct(
        private CashOnDeliveryGateway $cod,
        private PaymentTransitions $transitions,
    ) {}

    public function handle(Payment $payment, Money $collected, ?int $userId = null, ?string $reference = null): Payment
    {
        if ($payment->method !== PaymentMethod::Cod) {
            throw new InvalidArgumentException('Only a COD payment is collected on delivery.');
        }

        return DB::transaction(fn (): Payment => $this->transitions->apply(
            $payment, $this->cod->collected($payment, $collected, $reference), $userId,
        ));
    }
}

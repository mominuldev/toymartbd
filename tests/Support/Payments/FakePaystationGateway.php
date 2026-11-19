<?php

declare(strict_types=1);

namespace Tests\Support\Payments;

use App\Domain\Payment\Enums\PaymentMethod;

final class FakePaystationGateway extends ModeAwareGatewayFake
{
    public function method(): PaymentMethod
    {
        return PaymentMethod::Paystation;
    }
}

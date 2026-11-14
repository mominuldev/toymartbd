<?php

declare(strict_types=1);

namespace App\Domain\Pos\Tenders;

use App\Domain\Payment\Enums\PaymentMethod;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Counter tender → driver (rule 5), in the order the register shows them (Figma: Cash,
 * bKash, Nagad, Card). Kept apart from Payment's PaymentGatewayRegistry on purpose: that
 * registry maps a method to its ONLINE driver (a future bKash checkout session); at the
 * counter the same method is a manual tender. Two contexts, one `payments.method` value.
 */
final readonly class TenderRegistry
{
    /** @var array<string, class-string<TenderDriver>> */
    public const array DRIVERS = [
        'cash' => CashTender::class,
        'bkash' => BkashTender::class,
        'nagad' => NagadTender::class,
        'card' => CardTender::class,
    ];

    public function __construct(private Container $container) {}

    /** @return list<string> */
    public static function methods(): array
    {
        return array_keys(self::DRIVERS);
    }

    public function for(PaymentMethod $method): TenderDriver
    {
        $class = self::DRIVERS[$method->value] ?? throw new InvalidArgumentException("No counter tender for {$method->value}.");

        /** @var TenderDriver */
        return $this->container->make($class);
    }
}

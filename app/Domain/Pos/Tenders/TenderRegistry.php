<?php

declare(strict_types=1);

namespace App\Domain\Pos\Tenders;

use App\Domain\Pos\Enums\CounterTender;
use Illuminate\Contracts\Container\Container;

/**
 * Counter tender → driver (rule 5), in the order the register shows them (Figma: Cash,
 * bKash, Nagad, Card, then Due — last, and only for a cashier with `pos.sell_on_due`). Kept apart from Payment's PaymentGatewayRegistry on purpose: that
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
        'due' => DueTender::class,
    ];

    public function __construct(private Container $container) {}

    /** @return list<string> every counter tender, due included */
    public static function methods(): array
    {
        return array_keys(self::DRIVERS);
    }

    /** @return list<string> the tenders that move money (a payment row) — what a due collection accepts */
    public static function moneyMethods(): array
    {
        return array_values(array_filter(self::methods(), static fn (string $m): bool => CounterTender::from($m)->paymentMethod() !== null));
    }

    public function for(CounterTender $method): TenderDriver
    {
        $class = self::DRIVERS[$method->value];

        /** @var TenderDriver */
        return $this->container->make($class);
    }
}

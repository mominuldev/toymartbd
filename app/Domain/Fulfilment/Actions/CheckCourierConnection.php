<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Contracts\ChecksConnection;
use App\Domain\Fulfilment\DTOs\ConnectionCheck;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Services\CourierRegistry;

/**
 * Settings › Couriers › "Check connection": asks the courier, with the saved credentials, one
 * read-only question (Steadfast: the balance; Pathao: the store list). The external call runs
 * outside any transaction (rule 4); a balance the courier reported is kept on the provider row
 * with when it was read. Never books, never changes `verified` (rule 33a).
 */
final readonly class CheckCourierConnection
{
    public function __construct(private CourierRegistry $registry) {}

    public function handle(CourierProvider $provider): ConnectionCheck
    {
        if (! $this->registry->supports($provider->code)) {
            return ConnectionCheck::failed('unsupported');
        }
        $driver = $this->registry->forProvider($provider);
        if (! $driver instanceof ChecksConnection) {
            return ConnectionCheck::failed('unsupported');
        }

        $result = $driver->checkConnection();

        if ($result->ok && $result->balance !== null) {
            $provider->forceFill(['balance' => $result->balance, 'balance_checked_at' => $result->checkedAt])->save();
        }

        return $result;
    }
}

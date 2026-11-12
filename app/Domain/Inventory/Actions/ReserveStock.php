<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Services\StockReservations;
use Illuminate\Support\Facades\DB;

/**
 * Hold stock for an order's lines (all or nothing). Checkout's PlaceOrder will call
 * StockReservations inside its own transaction instead (DATABASE-DESIGN §4.1); this
 * Action is the standalone use case.
 */
final readonly class ReserveStock
{
    public function __construct(private StockReservations $reservations) {}

    /**
     * @param  array<int, int>  $lines  variant id → quantity
     *
     * @throws InsufficientStock
     */
    public function handle(array $lines): void
    {
        DB::transaction(fn () => $this->reservations->reserve($lines));
    }
}

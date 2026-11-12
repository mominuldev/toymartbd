<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Services\StockReservations;
use Illuminate\Support\Facades\DB;

/** Return held stock to available (cancellation before dispatch, or an expired hold). */
final readonly class ReleaseStock
{
    public function __construct(private StockReservations $reservations) {}

    /** @param  array<int, int>  $lines  variant id → quantity */
    public function handle(array $lines): void
    {
        DB::transaction(fn () => $this->reservations->release($lines));
    }
}

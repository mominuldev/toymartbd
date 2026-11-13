<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockLedger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A parcel the courier brought back — refused or undeliverable (DATABASE-DESIGN §4.4
 * courier return). Every unit comes back in as `courier_return_in` at the line's snapshot
 * unit cost (rule 7), pointing at the order. Unlike a customer return there is no
 * inspection step: the parcel never reached the customer. StockLedger locks the levels
 * FOR UPDATE in ascending variant id (rule 9). Nested inside the caller's transaction.
 */
final readonly class RestockCourierReturn
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * @param  list<array{variant_id: int, quantity: int, unit_cost: string|null}>  $lines
     * @return list<StockMovement>
     */
    public function handle(array $lines, string $referenceType, int $referenceId, ?int $userId = null): array
    {
        usort($lines, static fn (array $a, array $b): int => $a['variant_id'] <=> $b['variant_id']);

        $movements = [];
        foreach ($lines as $line) {
            if ($line['quantity'] < 0) {
                throw new InvalidArgumentException('Returned quantities are never negative.');
            }
            if ($line['quantity'] === 0) {
                continue;
            }
            $movements[] = new StockMovementLine(
                $line['variant_id'], StockMovementType::CourierReturnIn, $line['quantity'], $line['unit_cost'],
                $referenceType, $referenceId, $userId, 'courier return',
            );
        }

        return DB::transaction(fn (): array => $this->ledger->record($movements));
    }
}

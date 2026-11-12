<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\DTOs\StockTransferData;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferItem;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Inventory\Services\StockLevelLocks;
use App\Domain\Inventory\Services\StockValuation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Moves stock between two locations (P5-INV, PRD I-14) in ONE transaction: a
 * `transfer_out` at the source and a `transfer_in` at the destination per line, so the
 * business's total on hand never changes and invariant §7.1 holds at both ends.
 *
 * Locks every level involved — both locations — in one pass ordered by (variant_id,
 * location_id) before reading anything (rule 9; StockLevelLocks::lockAcross), so two
 * opposite transfers, or a transfer and a checkout reserving at the source, queue
 * instead of deadlocking. A transfer may only take what is AVAILABLE at the source
 * (on_hand − reserved): units held for an open order stay where the order will ship
 * from. Every line is checked before any is written.
 *
 * The destination's weighted-average cost absorbs the units at the source's cost basis,
 * so valuation moves with the stock rather than being re-priced by the move. There is
 * no in-transit step (StockTransferStatus).
 */
final readonly class TransferStock
{
    public function __construct(
        private StockLevelLocks $locks,
        private StockLedger $ledger,
    ) {}

    /**
     * @throws ValidationException naming each line that exceeds what is available (`lines.N.quantity`)
     */
    public function handle(StockTransferData $data, ?int $userId = null): StockTransfer
    {
        if ($data->fromLocationId === $data->toLocationId) {
            throw new InvalidArgumentException('A transfer needs two different locations.');
        }
        if ($data->lines === []) {
            throw new InvalidArgumentException('A transfer needs at least one line.');
        }
        foreach ($data->lines as $quantity) {
            if ($quantity <= 0) {
                throw new InvalidArgumentException('Transfer quantities are positive.');
            }
        }

        return DB::transaction(function () use ($data, $userId): StockTransfer {
            $lines = $data->lines;
            ksort($lines);
            $variantIds = array_keys($lines);

            $levels = $this->locks->lockAcross($variantIds, [$data->fromLocationId, $data->toLocationId]);
            $from = $levels[$data->fromLocationId];
            $to = $levels[$data->toLocationId];

            $errors = [];
            $index = 0;
            foreach ($lines as $variantId => $quantity) {
                if ($quantity > $from[$variantId]->available()) {
                    $errors['lines.'.$index.'.quantity'] = __('inventory.transfer.exceeds_available', ['available' => max(0, $from[$variantId]->available())]);
                }
                $index++;
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $costPrices = DB::table('product_variants')->whereIn('id', $variantIds)->pluck('cost_price', 'id');

            $transfer = StockTransfer::query()->create([
                'from_location_id' => $data->fromLocationId,
                'to_location_id' => $data->toLocationId,
                'status' => StockTransferStatus::Completed,
                'note' => $data->note,
                'user_id' => $userId,
            ]);
            $transfer->number = StockTransfer::numberFor($transfer->id, $transfer->created_at ?? now());
            $transfer->save();

            $out = [];
            $in = [];
            foreach ($lines as $variantId => $quantity) {
                $unitCost = StockValuation::costBasis($from[$variantId]->avg_cost, $costPrices[$variantId] ?? null);
                StockTransferItem::query()->create([
                    'transfer_id' => $transfer->id,
                    'variant_id' => $variantId,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                ]);
                $this->absorbCost($to[$variantId], $from[$variantId], $quantity, $unitCost, $costPrices[$variantId] ?? null);

                $line = static fn (StockMovementType $type): StockMovementLine => new StockMovementLine(
                    $variantId, $type, $quantity,
                    unitCost: $unitCost,
                    referenceType: StockTransfer::REFERENCE_TYPE,
                    referenceId: $transfer->id,
                    userId: $userId,
                    note: $data->note,
                );
                $out[] = $line(StockMovementType::TransferOut);
                $in[] = $line(StockMovementType::TransferIn);
            }

            $this->ledger->record($out, $data->fromLocationId);
            $this->ledger->record($in, $data->toLocationId);

            return $transfer->load('items');
        });
    }

    /**
     * Blend the incoming units into the destination's weighted-average cost. Left at 0
     * when neither end has one yet — both then fall back to the variant's cost price
     * (StockValuation::costBasis), which is already consistent.
     */
    private function absorbCost(StockLevel $to, StockLevel $from, int $quantity, string $unitCost, mixed $costPrice): void
    {
        if (bccomp((string) $to->avg_cost, '0', 4) <= 0 && bccomp((string) $from->avg_cost, '0', 4) <= 0) {
            return;
        }
        $held = max(0, $to->on_hand);
        $heldCost = StockValuation::costBasis($to->avg_cost, is_scalar($costPrice) ? (string) $costPrice : null);
        $total = bcadd(bcmul((string) $held, $heldCost, 6), bcmul((string) $quantity, $unitCost, 6), 6);
        $to->avg_cost = bcdiv($total, (string) ($held + $quantity), 4);
        $to->save();
    }
}

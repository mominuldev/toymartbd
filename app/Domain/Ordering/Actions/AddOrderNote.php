<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderNote;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Append a staff note to an order (P4-ORD-05). Notes are append-only; a correction is a
 * new note. Any status works — a note on a delivered or cancelled order is still history.
 * The body is never logged (it may hold a phone number or an address, rule 20).
 */
final readonly class AddOrderNote
{
    public function handle(Order $order, string $body, bool $customerVisible = false, ?int $userId = null): OrderNote
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > OrderNote::MAX_LENGTH) {
            throw new InvalidArgumentException('An order note needs 1–'.OrderNote::MAX_LENGTH.' characters.');
        }

        return DB::transaction(static fn (): OrderNote => OrderNote::query()->create([
            'order_id' => $order->id,
            'user_id' => $userId,
            'body' => $body,
            'is_customer_visible' => $customerVisible,
        ]));
    }
}

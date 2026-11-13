<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerNote;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Append a staff note to a customer (checklist §2.8). Append-only; a pinned note is shown
 * on the profile's summary rail. The body is never logged (rule 20).
 */
final readonly class AddCustomerNote
{
    public function handle(Customer $customer, string $body, bool $pinned = false, ?int $userId = null): CustomerNote
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > CustomerNote::MAX_LENGTH) {
            throw new InvalidArgumentException('A customer note needs 1–'.CustomerNote::MAX_LENGTH.' characters.');
        }

        return DB::transaction(static fn (): CustomerNote => CustomerNote::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $userId,
            'body' => $body,
            'is_pinned' => $pinned,
        ]));
    }
}

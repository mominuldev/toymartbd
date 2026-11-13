<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Events\CustomerChildRemoved;
use App\Domain\Customer\Models\CustomerChild;
use Illuminate\Support\Facades\DB;

/**
 * Remove a child (P8 batch 3). DELETED, not soft-deleted — children's data the parent asked us
 * to drop. After commit, {@see CustomerChildRemoved} lets Notification blank the name from the
 * birthday-reminder messages it logged.
 */
final readonly class RemoveCustomerChild
{
    public function handle(CustomerChild $child): void
    {
        DB::transaction(static function () use ($child): void {
            $child->delete();
            event(new CustomerChildRemoved($child->customer_id, $child->id));
        });
    }
}

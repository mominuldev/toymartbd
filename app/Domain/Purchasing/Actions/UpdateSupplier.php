<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\SupplierStatus;
use App\Domain\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\DB;

/** A partial update of a supplier; `status` may reactivate an archived one. */
final readonly class UpdateSupplier
{
    /** @param  array<string, mixed>  $changes  column → value, already validated */
    public function handle(Supplier $supplier, array $changes): Supplier
    {
        return DB::transaction(static function () use ($supplier, $changes): Supplier {
            if (isset($changes['status']) && is_string($changes['status'])) {
                $changes['status'] = SupplierStatus::from($changes['status']);
            }
            $supplier->fill($changes)->save();

            return $supplier;
        });
    }
}

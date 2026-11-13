<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\SupplierStatus;
use App\Domain\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\DB;

/** Archive, never delete (purchases and payments reference the supplier). Idempotent. */
final readonly class ArchiveSupplier
{
    public function handle(Supplier $supplier): void
    {
        DB::transaction(static function () use ($supplier): void {
            $supplier->status = SupplierStatus::Archived;
            $supplier->save();
        });
    }
}

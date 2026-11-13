<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\DTOs\SupplierData;
use App\Domain\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\DB;

final readonly class CreateSupplier
{
    public function handle(SupplierData $data): Supplier
    {
        return DB::transaction(static fn (): Supplier => Supplier::query()->create($data->toAttributes()));
    }
}

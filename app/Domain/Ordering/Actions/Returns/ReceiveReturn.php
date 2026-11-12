<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions\Returns;

use App\Domain\Ordering\Enums\ReturnStatus;
use App\Domain\Ordering\Exceptions\ReturnNotAllowed;
use App\Domain\Ordering\Models\OrderReturn;
use Illuminate\Support\Facades\DB;

/** The parcel is back at the warehouse, not yet inspected: requested → received. */
final readonly class ReceiveReturn
{
    public function handle(OrderReturn $return): OrderReturn
    {
        return DB::transaction(static function () use ($return): OrderReturn {
            /** @var OrderReturn $locked */
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);
            if (! $locked->status->canTransitionTo(ReturnStatus::Received)) {
                throw ReturnNotAllowed::returnStatus($locked->number, $locked->status->value, ReturnStatus::Received->value);
            }
            $locked->status = ReturnStatus::Received;
            $locked->received_at = now();
            $locked->save();

            return $locked;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions\Returns;

use App\Domain\Ordering\Enums\ReturnStatus;
use App\Domain\Ordering\Exceptions\ReturnNotAllowed;
use App\Domain\Ordering\Models\OrderReturn;
use Illuminate\Support\Facades\DB;

/**
 * Refuse a return before inspection (never came back, outside the return policy). Its
 * quantities stop counting against the order lines; nothing else moved, so nothing else
 * changes.
 */
final readonly class RejectReturn
{
    public function handle(OrderReturn $return, string $note): OrderReturn
    {
        return DB::transaction(static function () use ($return, $note): OrderReturn {
            /** @var OrderReturn $locked */
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);
            if (! $locked->status->canTransitionTo(ReturnStatus::Rejected)) {
                throw ReturnNotAllowed::returnStatus($locked->number, $locked->status->value, ReturnStatus::Rejected->value);
            }
            $locked->status = ReturnStatus::Rejected;
            $locked->note = trim($note);
            $locked->closed_at = now();
            $locked->save();

            return $locked;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Ordering\DTOs\BulkOrderOutcome;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One order's result in a bulk action: `outcome` is `done`, `conflict` (the state machine
 * refused — `status` is where the order is now) or `not_found`.
 *
 * @property BulkOrderOutcome $resource
 */
#[SchemaName('OrderBulkResult')]
final class OrderBulkResultResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $o = $this->resource;

        return [
            'order_id' => $o->orderId,
            /** @var string|null */
            'number' => $o->number,
            /** @var 'done'|'conflict'|'not_found' */
            'outcome' => $o->outcome,
            /** @var string|null */
            'status' => $o->status,
            /** @var string|null */
            'detail' => $o->detail,
        ];
    }
}

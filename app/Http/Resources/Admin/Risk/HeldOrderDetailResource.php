<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Risk;

use App\Domain\Risk\Models\RiskVerificationCall;
use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The verification queue's side panel for one order: the queue row, the call log, and the
 * phone's lookup (our profile + courier panel, separately — rule 24).
 */
final class HeldOrderDetailResource extends ApiResource
{
    /** @param  Collection<int, RiskVerificationCall>  $calls */
    public function __construct(HeldOrder $held, private Collection $calls, private PhoneRiskView $phone)
    {
        parent::__construct($held);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var HeldOrder $held */
        $held = $this->resource;

        return [
            'order' => (new RiskQueueItemResource($held))->toArray($request),
            'call_log' => RiskVerificationCallResource::collection($this->calls)->toArray($request),
            'phone' => (new PhoneRiskResource($this->phone))->toArray($request),
        ];
    }
}

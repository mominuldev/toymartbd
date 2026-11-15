<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pos;

use App\Domain\Pos\DTOs\DueCollectionData;
use App\Http\Middleware\EnsureIdempotency;

/** `POST /pos/sessions/{session}/due-collections` — take the money. Requires an `Idempotency-Key` (rule 17). */
final class CollectDueRequest extends PosDueCollectionRequest
{
    public function toData(): DueCollectionData
    {
        $key = $this->header(EnsureIdempotency::HEADER);

        return new DueCollectionData(
            sessionId: $this->drawer()->id,
            phone: $this->phone(),
            tenders: $this->tenders(),
            cashierId: (int) $this->user()?->getAuthIdentifier(),
            idempotencyKey: is_string($key) && $key !== '' ? $key : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront\Account;

use App\Domain\Notification\Models\MessageConsent;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The signed-in customer's own message consents (P8). No row = not consented. Which staff
 * member recorded a change is not shown to the customer.
 *
 * @property MessageConsent|null $resource
 */
#[SchemaName('StorefrontAccountConsents')]
final class AccountConsentResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            /** @var array{consented: bool, consented_at: string|null, withdrawn_at: string|null} */
            'birthday' => [
                'consented' => $row !== null && $row->consented,
                'consented_at' => $row?->consented_at?->format('Y-m-d\TH:i:sP'),
                'withdrawn_at' => $row?->withdrawn_at?->format('Y-m-d\TH:i:sP'),
            ],
        ];
    }
}

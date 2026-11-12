<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront\Account;

use App\Domain\Customer\DTOs\OtpIssued;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A code is on its way by SMS. The same answer for a known and an unknown phone.
 *
 * @property OtpIssued $resource
 */
#[SchemaName('StorefrontOtpIssued')]
final class OtpIssuedResource extends ApiResource
{
    /** @return array{expires_in: int, resend_in: int, length: int} */
    public function toArray(Request $request): array
    {
        return [
            'expires_in' => $this->resource->expiresIn,
            'resend_in' => $this->resource->resendIn,
            'length' => $this->resource->length,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\DTOs;

/**
 * One save of Store settings › Business. `values` is keyed by CompanyDetails::BUSINESS_FIELDS
 * (null removes the saved value); `whatsapp` is E.164 or null; `messenger` is the Facebook Page
 * username or numeric id behind `https://m.me/{value}`, or null.
 */
final readonly class BusinessDetailsData
{
    /** @param  array<string, string|null>  $values */
    public function __construct(public array $values, public ?string $whatsapp, public ?string $messenger = null) {}
}

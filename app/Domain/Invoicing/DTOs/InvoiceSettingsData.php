<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\DTOs;

/**
 * One save of Admin → Settings → Invoice details. Each value replaces what is stored; null
 * removes it (the invoice then prints "[placeholder]" for a required field).
 */
final readonly class InvoiceSettingsData
{
    /** @param  array<string, string|int|null>  $values  keyed by CompanyDetails::FIELDS */
    public function __construct(public array $values) {}
}

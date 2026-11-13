<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\DTOs\InvoiceSettingsData;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Saves the company details printed on invoices (`settings`, group `store`). Affects only
 * invoices issued AFTER the save: every issued invoice keeps its own snapshot (PRD N-13).
 */
final readonly class UpdateInvoiceSettings
{
    public function handle(InvoiceSettingsData $data, ?int $userId): void
    {
        DB::transaction(function () use ($data, $userId): void {
            foreach (array_keys(CompanyDetails::FIELDS) as $field) {
                $key = CompanyDetails::PREFIX.$field;
                $value = $data->values[$field] ?? null;
                if ($value === null || $value === '') {
                    Setting::query()->whereKey($key)->delete();

                    continue;
                }
                Setting::put($key, $value, SettingGroup::Store, false, $userId);
            }
        });
    }
}

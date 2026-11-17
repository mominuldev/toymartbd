<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Services\PrintSettings;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Saves Settings › Invoices, receipts & labels: one row per document, the whole document at
 * once. Applies from the next print; `updated_by` records who saved it.
 */
final readonly class UpdatePrintSettings
{
    /** @param  array<string, array<string, mixed>>  $documents  document → values (only the documents sent are saved) */
    public function handle(array $documents, ?int $userId): void
    {
        DB::transaction(function () use ($documents, $userId): void {
            foreach ($documents as $doc => $values) {
                if (! array_key_exists($doc, PrintSettings::SCHEMA)) {
                    continue;
                }
                Setting::put(PrintSettings::PREFIX.$doc, PrintSettings::clean($doc, $values), SettingGroup::Store, false, $userId);
            }
        });
    }
}

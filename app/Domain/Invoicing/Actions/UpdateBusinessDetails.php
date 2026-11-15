<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\DTOs\BusinessDetailsData;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Saves Store settings › Business: store name (EN/BN), trade licence, BIN and hotline into the
 * very `invoice.company.*` rows Invoice details reads and writes — one value, two screens —
 * plus the WhatsApp number and the Messenger Page. Touches nothing else (address, logo and footer stay as they are).
 * Like an invoice-details save, it affects only invoices issued afterwards.
 */
final readonly class UpdateBusinessDetails
{
    public function handle(BusinessDetailsData $data, ?int $userId): void
    {
        DB::transaction(function () use ($data, $userId): void {
            foreach (CompanyDetails::BUSINESS_FIELDS as $field) {
                self::write(CompanyDetails::PREFIX.$field, $data->values[$field] ?? null, $userId);
            }
            self::write(CompanyDetails::WHATSAPP_KEY, $data->whatsapp, $userId);
            self::write(CompanyDetails::MESSENGER_KEY, $data->messenger, $userId);
        });
    }

    private static function write(string $key, ?string $value, ?int $userId): void
    {
        if ($value === null || $value === '') {
            Setting::query()->whereKey($key)->delete();

            return;
        }
        Setting::put($key, $value, SettingGroup::Store, false, $userId);
    }
}

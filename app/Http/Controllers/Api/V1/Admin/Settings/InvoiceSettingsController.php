<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Invoicing\Actions\UpdateInvoiceSettings;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Settings\UpdateInvoiceSettingsRequest;
use App\Http\Resources\Admin\InvoiceSettingsResource;
use App\Models\Enums\Permission;

/**
 * Admin → Settings → Invoice details (P8, N-13): business name, address, hotline, trade
 * licence, BIN, logo and footer printed on invoices. Read with settings.view, save with
 * settings.update. A save never touches invoices already issued (each has its snapshot).
 */
final class InvoiceSettingsController extends ApiController
{
    /** `GET /settings/invoice` */
    public function show(CompanyDetails $company): InvoiceSettingsResource
    {
        $this->authorize(Permission::SettingsView->value);

        return self::view($company);
    }

    /** `PUT /settings/invoice` */
    public function update(UpdateInvoiceSettingsRequest $request, UpdateInvoiceSettings $update, CompanyDetails $company): InvoiceSettingsResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $update->handle($request->toData(), is_int($userId) ? $userId : null);

        return self::view($company);
    }

    private static function view(CompanyDetails $company): InvoiceSettingsResource
    {
        $snapshot = $company->snapshot();
        $saved = $company->saved();
        $logo = is_int($saved['logo_media_id']) ? Media::query()->find($saved['logo_media_id']) : null;

        return new InvoiceSettingsResource([
            'values' => $saved,
            'placeholders' => array_values($snapshot['placeholders']),
            'logo_url' => $logo instanceof Media ? $logo->url(320) : null,
        ]);
    }
}

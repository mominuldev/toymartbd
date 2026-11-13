<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

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

        return new InvoiceSettingsResource(['values' => $company->saved(), 'placeholders' => array_values($snapshot['placeholders'])]);
    }
}

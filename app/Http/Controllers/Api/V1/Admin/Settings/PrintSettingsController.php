<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Invoicing\Actions\UpdatePrintSettings;
use App\Domain\Invoicing\Services\PrintSettings;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Settings\UpdatePrintSettingsRequest;
use App\Http\Resources\Admin\PrintSettingsResource;
use App\Models\Enums\Permission;

/**
 * Settings › Invoices, receipts & labels (Figma 528:81351): what the A4 invoice, the POS
 * receipt and barcode labels show. Read with settings.view, save with settings.update.
 */
final class PrintSettingsController extends ApiController
{
    /** `GET /settings/print` */
    public function show(PrintSettings $print): PrintSettingsResource
    {
        $this->authorize(Permission::SettingsView->value);

        return self::view($print);
    }

    /** `PUT /settings/print` */
    public function update(UpdatePrintSettingsRequest $request, UpdatePrintSettings $update, PrintSettings $print): PrintSettingsResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $update->handle($request->documents(), is_int($userId) ? $userId : null);

        return self::view($print);
    }

    private static function view(PrintSettings $print): PrintSettingsResource
    {
        return new PrintSettingsResource([...$print->all(), 'saved' => $print->saved()]);
    }
}

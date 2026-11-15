<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Pos\Actions\UpdatePosSettings;
use App\Domain\Pos\Services\PosDiscountSettings;
use App\Domain\Pos\Services\PosDueSettings;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Settings\UpdatePosSettingsRequest;
use App\Http\Resources\Admin\PosSettingsResource;
use App\Models\Enums\Permission;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;

/**
 * Store settings › Point of sale: the cap on a cashier's discount (rule 33d) and the due limit
 * per customer (rule 33c). Read with settings.view, save with settings.update.
 */
final class PosSettingsController extends ApiController
{
    /** `GET /settings/pos` */
    public function show(PosDiscountSettings $discounts, PosDueSettings $dues): PosSettingsResource
    {
        $this->authorize(Permission::SettingsView->value);

        return self::view($discounts, $dues);
    }

    /** `PUT /settings/pos` */
    public function update(UpdatePosSettingsRequest $request, UpdatePosSettings $update, PosDiscountSettings $discounts, PosDueSettings $dues): PosSettingsResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $update->handle($request->maxPercent(), $request->dueLimit(), is_int($userId) ? $userId : null);
        $discounts->refresh();
        $dues->refresh();

        return self::view($discounts, $dues);
    }

    private static function view(PosDiscountSettings $discounts, PosDueSettings $dues): PosSettingsResource
    {
        $saved = Setting::query()->where('group', SettingGroup::Checkout->value)
            ->whereKey([PosDiscountSettings::KEY, PosDueSettings::KEY])->pluck('key')->all();

        return new PosSettingsResource([
            'discount_max_percent' => $discounts->maxPercent(),
            'discount_saved' => in_array(PosDiscountSettings::KEY, $saved, true),
            'due_limit' => $dues->limit()->toDecimal(),
            'due_saved' => in_array(PosDueSettings::KEY, $saved, true),
        ]);
    }
}

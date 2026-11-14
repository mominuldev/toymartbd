<?php

declare(strict_types=1);

use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Notification\Support\StoreHotline;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;

/*
 * Store settings → Business is the single source of the hotline printed in order messages
 * (Phase 10 batch 2). STORE_HOTLINE applies only until the owner saves one.
 */

it('uses STORE_HOTLINE until a hotline is saved in Business, then the saved one', function (): void {
    config(['notifications.hotline' => '09600123456']);
    expect(StoreHotline::current())->toBe('09600123456');

    Setting::put(CompanyDetails::PREFIX.'hotline', '09610-777888', SettingGroup::Store, false, null);

    expect(StoreHotline::current())->toBe('09610-777888');
});

it('reads the same key the Business screen and invoices write', function (): void {
    expect(StoreHotline::SETTING_KEY)->toBe(CompanyDetails::PREFIX.'hotline');
});

it('ignores a blank saved hotline', function (): void {
    config(['notifications.hotline' => '09600123456']);
    Setting::put(CompanyDetails::PREFIX.'hotline', '   ', SettingGroup::Store, false, null);

    expect(StoreHotline::current())->toBe('09600123456');
});

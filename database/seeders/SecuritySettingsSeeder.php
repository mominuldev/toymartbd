<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Staff\Services\TwoFactor;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Security settings with their defaults (reference data, every environment).
 *
 * Two-factor sign-in starts OFF (owner request 2026-11-16): staff may set it up, nobody is made
 * to. The owner switches it on at Staff & roles › Two-factor sign-in. A value already saved is
 * never overwritten, so re-seeding cannot undo the owner's choice.
 */
class SecuritySettingsSeeder extends Seeder
{
    public function run(): void
    {
        if (Setting::query()->find(TwoFactor::SETTING) === null) {
            Setting::put(TwoFactor::SETTING, false, SettingGroup::System);
        }
    }
}

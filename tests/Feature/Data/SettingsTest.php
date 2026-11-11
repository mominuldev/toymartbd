<?php

declare(strict_types=1);

use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('round-trips typed values', function (mixed $value): void {
    Setting::put('test.value', $value, SettingGroup::System);

    expect(Setting::get('test.value'))->toBe($value);
})->with([
    'string' => 'Toy Mart BD',
    'int' => 60,
    'float' => 1.5,
    'whole float' => 2.0,
    'bool true' => true,
    'bool false' => false,
    'null' => null,
    'list' => [['bkash', 'nagad', 'cod']],
    'map' => [['dhaka' => '60.00', 'outside' => '120.00']],
]);

it('round-trips Bangla unchanged, stored unescaped', function (): void {
    $name = 'টয় মার্ট বিডি — যুক্তাক্ষর ক্ষ ত্র জ্ঞ';

    Setting::put('store.name_bn', $name, SettingGroup::Store);

    expect(Setting::get('store.name_bn'))->toBe($name)
        ->and((string) DB::table('settings')->where('key', 'store.name_bn')->value('value'))->toContain('টয় মার্ট');
});

it('round-trips a Bangla map', function (): void {
    $value = ['en' => 'Free delivery over ৳2000', 'bn' => '৳২০০০ এর বেশি অর্ডারে ফ্রি ডেলিভারি'];

    Setting::put('checkout.banner', $value, SettingGroup::Checkout);

    // MySQL's JSON type normalises object key order, so compare by key, not position.
    expect(Setting::get('checkout.banner'))->toEqual($value);
});

it('encrypts values at rest when flagged', function (): void {
    $secret = ['api_key' => 'sk_live_super_secret', 'note' => 'গোপন'];

    Setting::put('courier.pathao.credentials', $secret, SettingGroup::Courier, encrypted: true);

    $raw = (string) DB::table('settings')->where('key', 'courier.pathao.credentials')->value('value');

    expect($raw)->not->toContain('sk_live_super_secret')
        ->and(Setting::get('courier.pathao.credentials'))->toBe($secret)
        ->and(Setting::query()->findOrFail('courier.pathao.credentials')->toArray())->not->toHaveKey('value');
});

it('updates in place by key and records the editor', function (): void {
    $user = User::factory()->create();

    Setting::put('store.hotline', '09600000000', SettingGroup::Store);
    Setting::put('store.hotline', '09611111111', SettingGroup::Store, updatedBy: $user->id);

    $setting = Setting::query()->findOrFail('store.hotline');

    expect(Setting::query()->count())->toBe(1)
        ->and($setting->value)->toBe('09611111111')
        ->and($setting->group)->toBe(SettingGroup::Store)
        ->and($setting->updatedBy?->is($user))->toBeTrue();
});

it('returns the default for a missing key', function (): void {
    expect(Setting::get('missing.key', 'fallback'))->toBe('fallback');
});

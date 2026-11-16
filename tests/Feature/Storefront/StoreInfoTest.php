<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Invoicing\Services\StoreBranding;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;

/*
 * GET /storefront/store-info (launch item F4): the storefront's contact and legal details come
 * from Admin → Settings → Store → Business — one source with invoices and order messages.
 */

const STORE_INFO_URL = '/api/v1/storefront/store-info';

beforeEach(function (): void {
    $this->token = User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
});

it('needs the storefront token', function (): void {
    $this->getJson(STORE_INFO_URL)->assertUnauthorized();
    $weak = User::factory()->create()->createToken('other', ['other'])->plainTextToken;
    $this->withToken($weak)->getJson(STORE_INFO_URL)->assertForbidden();
});

it('answers nulls (the storefront shows its marked samples) until the owner sets values', function (): void {
    config(['notifications.hotline' => '09600123456']); // the .env.example sample

    $this->withToken($this->token)->getJson(STORE_INFO_URL)
        ->assertOk()
        ->assertExactJson([
            'name_en' => 'Toy Mart BD',
            'name_bn' => 'টয় মার্ট বিডি',
            'hotline' => null,
            'hotline_tel' => null,
            'whatsapp' => null,
            'whatsapp_display' => null,
            'messenger' => null,
            'email' => null,
            'address_en' => null,
            'address_bn' => null,
            'trade_licence' => null,
            'bin' => null,
            'site_name_en' => null,
            'site_name_bn' => null,
            'tagline_en' => null,
            'tagline_bn' => null,
            'description_en' => null,
            'description_bn' => null,
            'logo' => null,
            'logo_dark' => null,
            'share_image' => null,
            'icons' => null,
        ]);
});

it('serves what the owner saved in Business and invoice details', function (): void {
    foreach (['name_en' => 'Toy Mart BD Ltd', 'hotline' => '09610-777888', 'email' => 'hello@toymartbd.com', 'trade_licence' => 'TRAD/DNCC/1/2026', 'bin' => '001122334-0101', 'address_bn' => 'মিরপুর, ঢাকা'] as $field => $value) {
        Setting::put(CompanyDetails::PREFIX.$field, $value, SettingGroup::Store, false, null);
    }
    Setting::put(CompanyDetails::WHATSAPP_KEY, '+8801711000222', SettingGroup::Store, false, null);
    Setting::put(CompanyDetails::MESSENGER_KEY, 'toymartbd', SettingGroup::Store, false, null);

    $this->withToken($this->token)->getJson(STORE_INFO_URL)
        ->assertOk()
        ->assertJsonPath('name_en', 'Toy Mart BD Ltd')
        ->assertJsonPath('hotline', '09610-777888')
        ->assertJsonPath('hotline_tel', 'tel:+8809610777888')
        ->assertJsonPath('whatsapp', '+8801711000222')
        ->assertJsonPath('whatsapp_display', '01711-000222')
        ->assertJsonPath('messenger', 'toymartbd')
        ->assertJsonPath('email', 'hello@toymartbd.com')
        ->assertJsonPath('address_bn', 'মিরপুর, ঢাকা')
        ->assertJsonPath('trade_licence', 'TRAD/DNCC/1/2026')
        ->assertJsonPath('bin', '001122334-0101');
});

it('uses a real STORE_HOTLINE until one is saved, but never a design sample', function (): void {
    config(['notifications.hotline' => '09612-345000']);
    $this->withToken($this->token)->getJson(STORE_INFO_URL)->assertJsonPath('hotline', '09612-345000');

    config(['notifications.hotline' => '09610-123456']);
    $this->withToken($this->token)->getJson(STORE_INFO_URL)->assertJsonPath('hotline', null);
});

it('purges the storefront store-info cache when Business or invoice details are saved', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh(), 'web');
    Queue::fake();

    $this->putJson('/api/v1/admin/settings/business', ['name_en' => 'Toy Mart BD', 'name_bn' => 'টয় মার্ট বিডি', 'hotline' => '09610-777888', 'trade_licence' => 'TRAD/1', 'bin' => '001122334-0101', 'whatsapp' => null])->assertOk();
    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['store-info'] && $job->afterCommit === true);

    $invoice = $this->getJson('/api/v1/admin/settings/invoice')->assertOk()->json();
    $values = array_intersect_key(is_array($invoice['values'] ?? null) ? $invoice['values'] : $invoice, array_flip(array_keys(CompanyDetails::FIELDS)));
    $this->putJson('/api/v1/admin/settings/invoice', [...$values, 'email' => 'hello@toymartbd.com'])->assertOk();
    Queue::assertPushed(RevalidateStorefrontTags::class, 2);
});

it('serves the Messenger Page saved in Business, and null again once it is cleared', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh(), 'web');
    Queue::fake();
    $body = ['name_en' => 'Toy Mart BD', 'name_bn' => 'টয় মার্ট বিডি', 'hotline' => '09610-777888', 'trade_licence' => 'TRAD/1', 'bin' => '001122334-0101', 'whatsapp' => '01711-000222'];

    $this->putJson('/api/v1/admin/settings/business', [...$body, 'messenger' => 'toymart.bd'])->assertOk();
    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['store-info']);
    $this->withToken($this->token)->getJson(STORE_INFO_URL)->assertOk()->assertJsonPath('messenger', 'toymart.bd')->assertJsonPath('whatsapp', '+8801711000222');

    $this->putJson('/api/v1/admin/settings/business', [...$body, 'messenger' => null])->assertOk();
    $this->withToken($this->token)->getJson(STORE_INFO_URL)->assertOk()->assertJsonPath('messenger', null);
});

it('serves Site & branding: texts per locale, the logos at ~480 px and the share image at 1200 px', function (): void {
    $logo = Media::factory()->create(['width' => 1000, 'height' => 300]);
    $dark = Media::factory()->create(['width' => 400, 'height' => 100, 'renditions' => [['w' => 160, 'f' => 'webp', 'b' => 1], ['w' => 320, 'f' => 'webp', 'b' => 1], ['w' => 400, 'f' => 'webp', 'b' => 1]]]);
    $share = Media::factory()->create(['width' => 2400, 'height' => 1256, 'renditions' => [['w' => 800, 'f' => 'webp', 'b' => 1], ['w' => 1200, 'f' => 'webp', 'b' => 1]]]);
    Setting::put(CompanyDetails::PREFIX.'logo_media_id', $logo->id, SettingGroup::Store);
    foreach (['site_name_en' => 'Toy Mart', 'site_name_bn' => 'টয় মার্ট', 'tagline_en' => 'Toys that teach', 'tagline_bn' => 'খেলনায় শেখা', 'description_en' => 'Educational toys, COD across Bangladesh.', 'description_bn' => 'শিক্ষামূলক খেলনা, সারা দেশে ক্যাশ অন ডেলিভারি।', 'logo_dark_media_id' => $dark->id, 'share_image_media_id' => $share->id] as $field => $value) {
        Setting::put(StoreBranding::PREFIX.$field, $value, SettingGroup::Store);
    }

    $this->withToken($this->token)->getJson(STORE_INFO_URL)
        ->assertOk()
        ->assertJsonPath('name_en', 'Toy Mart BD')
        ->assertJsonPath('site_name_en', 'Toy Mart')
        ->assertJsonPath('site_name_bn', 'টয় মার্ট')
        ->assertJsonPath('tagline_bn', 'খেলনায় শেখা')
        ->assertJsonPath('description_bn', 'শিক্ষামূলক খেলনা, সারা দেশে ক্যাশ অন ডেলিভারি।')
        ->assertJsonPath('logo', ['url' => $logo->url(480), 'width' => 480, 'height' => 144])
        ->assertJsonPath('logo.url', fn (string $url): bool => str_ends_with($url, '/480.webp'))
        ->assertJsonPath('logo_dark', ['url' => $dark->url(480), 'width' => 400, 'height' => 100])
        ->assertJsonPath('share_image', ['url' => $share->url(1200), 'width' => 1200, 'height' => 628])
        ->assertJsonPath('icons', null); // no icon chosen, nothing rendered
});

it('serves no image whose media is gone or not ready, and no favicons rendered from an earlier icon', function (): void {
    $processing = Media::factory()->create(['processing_status' => ImageProcessingStatus::Processing]);
    Setting::put(CompanyDetails::PREFIX.'logo_media_id', $processing->id, SettingGroup::Store);
    Setting::put(StoreBranding::PREFIX.'share_image_media_id', 999999, SettingGroup::Store);
    $icon = Media::factory()->create(['width' => 1024, 'height' => 1024]);
    Setting::put(StoreBranding::PREFIX.'icon_media_id', $icon->id, SettingGroup::Store);
    Setting::put(StoreBranding::ICON_PNGS_KEY, ['media_id' => $icon->id + 1, 'paths' => ['32' => 'a.png', '180' => 'b.png', '192' => 'c.png', '512' => 'd.png']], SettingGroup::Store);

    $this->withToken($this->token)->getJson(STORE_INFO_URL)
        ->assertOk()
        ->assertJsonPath('logo', null)
        ->assertJsonPath('share_image', null)
        ->assertJsonPath('icons', null);
});

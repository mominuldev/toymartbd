<?php

declare(strict_types=1);

use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Invoicing\Services\CompanyDetails;
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
            'site' => [
                'name_en' => 'Toy Mart BD',
                'name_bn' => 'টয় মার্ট বিডি',
                'tagline_en' => null,
                'tagline_bn' => null,
                'description_en' => null,
                'description_bn' => null,
                'logo' => null,
                'logo_dark' => null,
                'icons' => [],
                'social_image' => null,
            ],
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

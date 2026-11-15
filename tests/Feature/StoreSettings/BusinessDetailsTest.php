<?php

declare(strict_types=1);

use App\Domain\Invoicing\Services\CompanyDetails;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Tests\Feature\Orders\Support;
use Tests\Support\RoleMatrix;

/*
 * Store settings › Business (Figma B · 38). Single source of truth: name, trade licence,
 * BIN and hotline are the `invoice.company.*` rows Invoice details reads and writes, so a
 * save on either screen shows on the other; WhatsApp is its own key, a rule-30 mobile.
 */

const BUSINESS_URL = '/api/v1/admin/settings/business';
const INVOICE_SETTINGS_URL = '/api/v1/admin/settings/invoice';

/** @return array<string, string|null> */
function businessBody(array $overrides = []): array
{
    return [
        'name_en' => 'Toy Mart BD',
        'name_bn' => 'টয় মার্ট বিডি',
        'trade_licence' => 'TRAD/DNCC/045512/2025',
        'bin' => '004567891-0101',
        'hotline' => '09610-123456',
        'whatsapp' => '01711-000222',
        'messenger' => 'toymartbd',
        ...$overrides,
    ];
}

it('starts empty, with the brand names and env hotline as honest fallbacks', function (): void {
    config(['notifications.hotline' => '09600123456']);
    Support::actingAs(Role::Manager);

    $this->getJson(BUSINESS_URL)->assertOk()
        ->assertJsonPath('name_en', null)
        ->assertJsonPath('whatsapp', null)
        ->assertJsonPath('messenger', null)
        ->assertJsonPath('default_names.name_en', 'Toy Mart BD')
        ->assertJsonPath('hotline_default', '09600123456')
        ->assertJsonPath('placeholders', ['name_en', 'name_bn', 'trade_licence', 'bin', 'hotline']);
});

it('round-trips the business details, storing WhatsApp as E.164', function (): void {
    Support::actingAs(Role::Manager);

    $this->putJson(BUSINESS_URL, businessBody())->assertOk()
        ->assertJsonPath('name_bn', 'টয় মার্ট বিডি')
        ->assertJsonPath('trade_licence', 'TRAD/DNCC/045512/2025')
        ->assertJsonPath('whatsapp', '+8801711000222')
        ->assertJsonPath('messenger', 'toymartbd')
        ->assertJsonPath('placeholders', []);

    $this->getJson(BUSINESS_URL)->assertOk()->assertJsonPath('hotline', '09610-123456')->assertJsonPath('bin', '004567891-0101');
});

it('shares its values with invoice details, both ways, without touching the invoice-only fields', function (): void {
    Support::actingAs(Role::Manager);
    $this->putJson(INVOICE_SETTINGS_URL, ['name_en' => 'Old name', 'address_en' => 'House 7, Mirpur', 'footer_en' => 'Thanks!'])->assertOk();

    $this->putJson(BUSINESS_URL, businessBody())->assertOk();

    // Invoice details sees the Business values, and keeps its own address and footer.
    $this->getJson(INVOICE_SETTINGS_URL)->assertOk()
        ->assertJsonPath('name_en', 'Toy Mart BD')
        ->assertJsonPath('trade_licence', 'TRAD/DNCC/045512/2025')
        ->assertJsonPath('bin', '004567891-0101')
        ->assertJsonPath('hotline', '09610-123456')
        ->assertJsonPath('address_en', 'House 7, Mirpur')
        ->assertJsonPath('footer_en', 'Thanks!');
    expect(app(CompanyDetails::class)->snapshot())->toMatchArray(['name_en' => 'Toy Mart BD', 'bin' => '004567891-0101']);

    // And an invoice-details save shows on Business, leaving WhatsApp alone.
    $this->putJson(INVOICE_SETTINGS_URL, ['name_en' => 'Toy Mart Bangladesh', 'bin' => '004567891-0101'])->assertOk();
    $this->getJson(BUSINESS_URL)->assertOk()
        ->assertJsonPath('name_en', 'Toy Mart Bangladesh')
        ->assertJsonPath('trade_licence', null)
        ->assertJsonPath('whatsapp', '+8801711000222');
});

it('clears a value sent as null or blank', function (): void {
    Support::actingAs(Role::Manager);
    $this->putJson(BUSINESS_URL, businessBody())->assertOk();

    $this->putJson(BUSINESS_URL, businessBody(['whatsapp' => null, 'messenger' => ' ', 'bin' => '  ']))->assertOk()
        ->assertJsonPath('whatsapp', null)
        ->assertJsonPath('messenger', null)
        ->assertJsonPath('bin', null)
        ->assertJsonPath('placeholders', ['bin']);
});

it('validates WhatsApp as a 013–019 mobile (rule 30) and the hotline and BIN as invoices do', function (string $field, string $value): void {
    Support::actingAs(Role::Manager);

    $this->putJson(BUSINESS_URL, businessBody([$field => $value]))
        ->assertUnprocessable()
        ->assertJsonPath('type', fn (string $t): bool => str_ends_with($t, 'validation-error'))
        ->assertJsonPath('errors.0.field', $field);
})->with([
    'defunct 011' => ['whatsapp', '01111-000222'],
    '012' => ['whatsapp', '01211000222'],
    'landline as WhatsApp' => ['whatsapp', '09610-123456'],
    'letters in the hotline' => ['hotline', 'call us'],
    'letters in the BIN' => ['bin', 'not-a-bin!'],
    'Messenger as a URL' => ['messenger', 'https://m.me/toymartbd'],
    'Messenger too short' => ['messenger', 'toy'],
    'Messenger with a space' => ['messenger', 'toy mart bd'],
    'Messenger too long' => ['messenger', str_repeat('a', 51)],
]);

it('accepts a Messenger Page username with dots, or a numeric Page id', function (string $value): void {
    Support::actingAs(Role::Manager);

    $this->putJson(BUSINESS_URL, businessBody(['messenger' => " {$value} "]))->assertOk()->assertJsonPath('messenger', $value);
})->with(['username' => 'toymart.bd', 'numeric id' => '104857234567890']);

it('accepts a 09xxx landline hotline and a +880 WhatsApp', function (): void {
    Support::actingAs(Role::Manager);

    $this->putJson(BUSINESS_URL, businessBody(['hotline' => '09610 123456', 'whatsapp' => '+880 1911-000 222']))->assertOk()
        ->assertJsonPath('hotline', '09610 123456')
        ->assertJsonPath('whatsapp', '+8801911000222');
});

describe('role matrix', function (): void {
    it('guards GET /settings/business with settings.view', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(BUSINESS_URL));
    })->with(RoleMatrix::dataset(Permission::SettingsView));

    it('guards PUT /settings/business with settings.update', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson(BUSINESS_URL, businessBody()));
    })->with(RoleMatrix::dataset(Permission::SettingsUpdate));

    it('refuses a guest with 401', function (): void {
        $this->getJson(BUSINESS_URL)->assertUnauthorized();
    });
});

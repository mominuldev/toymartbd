<?php

declare(strict_types=1);

use App\Domain\Invoicing\Services\PrintSettings;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Setting;
use Tests\Feature\Orders\Support;
use Tests\Support\RoleMatrix;

/*
 * Settings › Invoices, receipts & labels: what the A4 invoice, the POS receipt and barcode
 * labels show. Figma defaults until saved; each document saved whole.
 */

const PRINT_SETTINGS_URL = '/api/v1/admin/settings/print';

it('shows the Figma defaults until anything is saved', function (): void {
    Support::actingAs(Role::Manager);

    $this->getJson(PRINT_SETTINGS_URL)->assertOk()
        ->assertExactJson([...PrintSettings::DEFAULTS, 'saved' => []]);
});

it('saves one document whole and leaves the others at their defaults', function (): void {
    $user = Support::actingAs(Role::Manager);
    $invoice = [...PrintSettings::DEFAULTS['invoice'], 'paper' => 'a5', 'language' => 'both', 'colour' => 'mono', 'show_sku' => false];

    $this->putJson(PRINT_SETTINGS_URL, ['invoice' => $invoice])->assertOk()
        ->assertJsonPath('invoice', $invoice)
        ->assertJsonPath('receipt', PrintSettings::DEFAULTS['receipt'])
        ->assertJsonPath('saved', ['invoice']);

    expect(Setting::query()->findOrFail('print.invoice')->updated_by)->toBe($user->id)
        ->and(app(PrintSettings::class)->invoice()['paper'])->toBe('a5');
});

it('saves the receipt with Bangla footer text and trims blanks to null', function (): void {
    Support::actingAs(Role::Manager);
    $receipt = [...PrintSettings::DEFAULTS['receipt'], 'paper' => '58', 'language' => 'bn', 'footer_en' => '  ', 'footer_bn' => 'ধন্যবাদ! ৭ দিনের মধ্যে বদল।'];

    $this->putJson(PRINT_SETTINGS_URL, ['receipt' => $receipt, 'labels' => [...PrintSettings::DEFAULTS['labels'], 'template' => 'a4_24', 'show_store' => false]])->assertOk()
        ->assertJsonPath('receipt.paper', '58')
        ->assertJsonPath('receipt.footer_en', null)
        ->assertJsonPath('receipt.footer_bn', 'ধন্যবাদ! ৭ দিনের মধ্যে বদল।')
        ->assertJsonPath('labels.template', 'a4_24')
        ->assertJsonPath('labels.show_store', false);
});

it('falls back to the default for a stored value no longer allowed', function (): void {
    Setting::put('print.labels', ['template' => 'roll_99x99', 'show_price' => 'yes'], App\Models\Enums\SettingGroup::Store);

    expect(app(PrintSettings::class)->labels())->toBe(PrintSettings::DEFAULTS['labels']);
});

it('rejects values outside the allow-lists', function (array $body, string $field): void {
    Support::actingAs(Role::Manager);
    $this->putJson(PRINT_SETTINGS_URL, $body)->assertStatus(422)->assertJsonPath('errors.0.field', $field);
})->with([
    'paper' => [['invoice' => [...PrintSettings::DEFAULTS['invoice'], 'paper' => 'letter']], 'invoice.paper'],
    'roll' => [['receipt' => [...PrintSettings::DEFAULTS['receipt'], 'paper' => '76']], 'receipt.paper'],
    'string bool' => [['labels' => [...PrintSettings::DEFAULTS['labels'], 'show_price' => 'true']], 'labels.show_price'],
    'template' => [['labels' => [...PrintSettings::DEFAULTS['labels'], 'template' => 'a4_99']], 'labels.template'],
    'unknown field' => [['invoice' => [...PrintSettings::DEFAULTS['invoice'], 'show_logo_big' => true]], 'invoice'],
    'unknown document' => [['labels' => PrintSettings::DEFAULTS['labels'], 'envelope' => []], 'envelope'],
    'empty' => [[], 'invoice'],
    'long footer' => [['receipt' => [...PrintSettings::DEFAULTS['receipt'], 'footer_en' => str_repeat('x', 161)]], 'receipt.footer_en'],
]);

describe('role matrix', function (): void {
    it('guards GET /settings/print with settings.view', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(PRINT_SETTINGS_URL));
    })->with(RoleMatrix::dataset(Permission::SettingsView));

    it('guards PUT /settings/print with settings.update', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson(PRINT_SETTINGS_URL, ['labels' => PrintSettings::DEFAULTS['labels']]));
    })->with(RoleMatrix::dataset(Permission::SettingsUpdate));

    it('refuses a guest with 401', function (): void {
        $this->getJson(PRINT_SETTINGS_URL)->assertUnauthorized();
    });
});

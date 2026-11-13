<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Actions\SendBirthdayMessages;
use App\Domain\Notification\Enums\MessageConsentPurpose;
use App\Domain\Notification\Models\AutomationSend;
use App\Domain\Notification\Models\MessageConsent;
use App\Models\Enums\Permission as P;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\RoleMatrix;

/*
 * P8 — staff record a customer's birthday-message consent (only with the customer's confirmed
 * agreement) and their date of birth on the admin profile (Figma B · 18 241:41374). The birth
 * date is personal data: shown and editable only with customers.view_pii (rule 20).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => 'https://toymartbd.com', 'services.storefront.revalidate_secret' => '']);
});

function bdayStaff(Role $role): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole($role)->create(['name' => 'Nadia Operator'])->refresh();
    app('auth')->forgetGuards();
    test()->actingAs($user, 'web');

    return $user;
}

it('guards the date-of-birth edit for every role (customers.update + customers.view_pii)', function (): void {
    $customer = Customer::factory()->create();
    // Every role holding customers.update also holds customers.view_pii today; the policy asks for both.
    foreach (Role::cases() as $role) {
        expect(RoleMatrix::allows($role, P::CustomersUpdate))->toBe(RoleMatrix::allows($role, P::CustomersUpdate) && RoleMatrix::allows($role, P::CustomersViewPii));
    }
    RoleMatrix::assert($this, P::CustomersUpdate, fn () => $this->putJson("/api/v1/admin/customers/{$customer->id}/date-of-birth", ['date_of_birth' => '1990-05-04']));
});

it('refuses the date-of-birth edit to a role with customers.update but no customers.view_pii', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create()->refresh();
    $user->givePermissionTo(P::CustomersView->value, P::CustomersUpdate->value);
    $this->actingAs($user, 'web');
    $customer = Customer::factory()->create();

    $this->putJson("/api/v1/admin/customers/{$customer->id}/date-of-birth", ['date_of_birth' => '1990-05-04'])->assertForbidden();
    expect($customer->refresh()->date_of_birth)->toBeNull();
});

it('shows the date of birth only with customers.view_pii', function (): void {
    $customer = Customer::factory()->create(['date_of_birth' => '1990-05-04']);

    bdayStaff(Role::OrderOperator);
    $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk()
        ->assertJsonPath('date_of_birth', '1990-05-04')->assertJsonPath('has_date_of_birth', true);

    bdayStaff(Role::Viewer);
    $body = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk()
        ->assertJsonPath('date_of_birth', null)->assertJsonPath('has_date_of_birth', true)->getContent();
    expect($body)->not->toContain('1990-05-04');
});

it('records, validates and clears the date of birth', function (): void {
    bdayStaff(Role::OrderOperator);
    $customer = Customer::factory()->create();
    $url = "/api/v1/admin/customers/{$customer->id}/date-of-birth";

    $this->putJson($url, ['date_of_birth' => '2016-02-29'])->assertOk()->assertExactJson(['id' => $customer->id, 'date_of_birth' => '2016-02-29']);
    foreach (['2099-01-01', '1899-12-31', '04/05/1990', '2017-02-29', now()->toDateString()] as $bad) {
        $this->putJson($url, ['date_of_birth' => $bad])->assertUnprocessable();
    }
    $this->putJson($url, [])->assertUnprocessable(); // "present": say null to clear it
    expect($customer->refresh()->date_of_birth?->format('Y-m-d'))->toBe('2016-02-29')
        ->and(MessageConsent::query()->count())->toBe(0); // a birth date is not a consent

    $this->putJson($url, ['date_of_birth' => null])->assertOk()->assertJsonPath('date_of_birth', null);
});

it('records consent only when staff confirm the customer agreed, with provenance', function (): void {
    $staff = bdayStaff(Role::OrderOperator);
    $customer = Customer::factory()->create(['date_of_birth' => '1990-11-13']);
    $url = "/api/v1/admin/notifications/customers/{$customer->id}/consents";

    $this->putJson($url, ['birthday' => true])->assertUnprocessable();
    $this->putJson($url, ['birthday' => true, 'customer_confirmed' => false])->assertUnprocessable();
    expect(MessageConsent::query()->count())->toBe(0);

    $this->putJson($url, ['birthday' => true, 'customer_confirmed' => true])->assertOk()
        ->assertJsonPath('birthday.consented', true)->assertJsonPath('birthday.source', 'admin')
        ->assertJsonPath('birthday.recorded_by', ['id' => $staff->id, 'name' => 'Nadia Operator'])
        ->assertJsonPath('birthday.withdrawn_at', null);

    // Withdrawal needs no confirmation, keeps the row and when it was given.
    $this->putJson($url, ['birthday' => false])->assertOk()
        ->assertJsonPath('birthday.consented', false)->assertJsonPath('birthday.source', 'admin');
    $row = MessageConsent::query()->sole();
    expect($row->withdrawn_at)->not->toBeNull()->and($row->consented_at)->not->toBeNull()->and($row->recorded_by)->toBe($staff->id);

    // ... and the next run sends nothing.
    expect(app(SendBirthdayMessages::class)->handle(Carbon::parse('2026-11-13 04:00:00')))->toBe(['queued' => 0, 'skipped' => 0])
        ->and(AutomationSend::query()->count())->toBe(0);
});

it('shows a storefront opt-in to staff with no staff recorder', function (): void {
    bdayStaff(Role::Viewer);
    $customer = Customer::factory()->create();
    app(App\Domain\Notification\Actions\SetMessageConsent::class)->handle($customer->id, MessageConsentPurpose::Birthday, true, 'storefront', null);

    $this->getJson("/api/v1/admin/notifications/customers/{$customer->id}/consents")->assertOk()
        ->assertJsonPath('birthday.consented', true)->assertJsonPath('birthday.source', 'storefront')->assertJsonPath('birthday.recorded_by', null);
});

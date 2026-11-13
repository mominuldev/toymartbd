<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerChild;
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
 * P8 (batch 3) — staff record a customer's consent to birthday reminders with offers (only with the
 * customer's confirmed agreement AND how it was given) and the customer's children on the admin
 * profile (Figma B · 18 241:41374: "Children", "Birthday reminders"). Children's data: shown and
 * editable only with customers.view_pii (rule 20). The date-of-birth endpoint is gone.
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

it('guards adding, editing and removing a child for every role (customers.update + customers.view_pii)', function (): void {
    $customer = Customer::factory()->create();
    // Every role holding customers.update also holds customers.view_pii today; the policy asks for both.
    foreach (Role::cases() as $role) {
        expect(RoleMatrix::allows($role, P::CustomersUpdate))->toBe(RoleMatrix::allows($role, P::CustomersUpdate) && RoleMatrix::allows($role, P::CustomersViewPii));
    }
    $body = ['name' => 'Arian', 'birth_month' => 3, 'birth_day' => 12, 'birth_year' => 2020];
    RoleMatrix::assert($this, P::CustomersUpdate, fn () => $this->postJson('/api/v1/admin/customers/'.Customer::factory()->create()->id.'/children', $body), 201);
    $child = CustomerChild::query()->create(['customer_id' => $customer->id, ...$body]);
    RoleMatrix::assert($this, P::CustomersUpdate, fn () => $this->patchJson("/api/v1/admin/customers/{$customer->id}/children/{$child->id}", $body));
    RoleMatrix::assert($this, P::CustomersUpdate, function () use ($customer, $body) {
        $doomed = CustomerChild::query()->create(['customer_id' => $customer->id, ...$body]);

        return $this->deleteJson("/api/v1/admin/customers/{$customer->id}/children/{$doomed->id}");
    }, 204);
});

it('refuses children edits to a role with customers.update but no customers.view_pii', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create()->refresh();
    $user->givePermissionTo(P::CustomersView->value, P::CustomersUpdate->value);
    $this->actingAs($user, 'web');
    $customer = Customer::factory()->create();
    $child = CustomerChild::query()->create(['customer_id' => $customer->id, 'name' => 'Arian', 'birth_month' => 3, 'birth_day' => 12]);

    $this->postJson("/api/v1/admin/customers/{$customer->id}/children", ['name' => 'X', 'birth_month' => 1, 'birth_day' => 1, 'birth_year' => null])->assertForbidden();
    $this->patchJson("/api/v1/admin/customers/{$customer->id}/children/{$child->id}", ['name' => 'X', 'birth_month' => 1, 'birth_day' => 1, 'birth_year' => null])->assertForbidden();
    $this->deleteJson("/api/v1/admin/customers/{$customer->id}/children/{$child->id}")->assertForbidden();
    expect(CustomerChild::query()->sole()->name)->toBe('Arian');
});

it('shows the children only with customers.view_pii; the count to everyone', function (): void {
    $customer = Customer::factory()->create();
    CustomerChild::query()->create(['customer_id' => $customer->id, 'name' => 'Mehrin', 'birth_month' => 8, 'birth_day' => 4, 'birth_year' => 2023]);
    CustomerChild::query()->create(['customer_id' => $customer->id, 'name' => 'Arian', 'birth_month' => 3, 'birth_day' => 12, 'birth_year' => null]);

    bdayStaff(Role::OrderOperator);
    $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk()
        ->assertJsonPath('children_count', 2)
        ->assertJsonPath('children.0.name', 'Arian')->assertJsonPath('children.0.age', null)
        ->assertJsonPath('children.1.name', 'Mehrin')->assertJsonPath('children.1.birth_day', 4)
        ->assertJsonMissingPath('date_of_birth')->assertJsonMissingPath('has_date_of_birth');

    bdayStaff(Role::Viewer);
    $body = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk()
        ->assertJsonPath('children_count', 2)->assertJsonPath('children', null)->getContent();
    expect($body)->not->toContain('Mehrin')->not->toContain('Arian');
});

it('adds, edits and removes a child; another customer\'s child is a 404; the date-of-birth endpoint is gone', function (): void {
    bdayStaff(Role::OrderOperator);
    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();
    $theirs = CustomerChild::query()->create(['customer_id' => $other->id, 'name' => 'Not yours', 'birth_month' => 1, 'birth_day' => 1]);
    $url = "/api/v1/admin/customers/{$customer->id}/children";

    $id = $this->postJson($url, ['name' => '  আয়ান  ', 'birth_month' => 2, 'birth_day' => 29, 'birth_year' => null])->assertCreated()
        ->assertJsonPath('name', 'আয়ান')->assertJsonPath('birth_day', 29)->json('id');
    $this->postJson($url, ['name' => 'X', 'birth_month' => 2, 'birth_day' => 30, 'birth_year' => null])->assertUnprocessable();
    $this->patchJson("{$url}/{$id}", ['name' => null, 'birth_month' => 2, 'birth_day' => 28, 'birth_year' => 2022])->assertOk()
        ->assertJsonPath('name', null)->assertJsonPath('birth_year', 2022);
    $this->patchJson("{$url}/{$theirs->id}", ['name' => 'X', 'birth_month' => 1, 'birth_day' => 1, 'birth_year' => null])->assertNotFound();
    $this->deleteJson("{$url}/{$theirs->id}")->assertNotFound();
    $this->deleteJson("{$url}/{$id}")->assertNoContent();

    expect(CustomerChild::query()->where('customer_id', $customer->id)->count())->toBe(0)
        ->and($theirs->refresh()->name)->toBe('Not yours');
    $this->putJson("/api/v1/admin/customers/{$customer->id}/date-of-birth", ['date_of_birth' => '1990-05-04'])->assertNotFound();
});

it('records consent only when staff confirm the customer agreed and say how, with provenance', function (): void {
    $staff = bdayStaff(Role::OrderOperator);
    $customer = Customer::factory()->create();
    CustomerChild::query()->create(['customer_id' => $customer->id, 'name' => 'Arian', 'birth_month' => 11, 'birth_day' => 15]);
    $url = "/api/v1/admin/notifications/customers/{$customer->id}/consents";

    $this->putJson($url, ['birthday' => true, 'confirmation_method' => 'phone_call'])->assertUnprocessable();
    $this->putJson($url, ['birthday' => true, 'customer_confirmed' => false, 'confirmation_method' => 'phone_call'])->assertUnprocessable();
    $this->putJson($url, ['birthday' => true, 'customer_confirmed' => true])->assertUnprocessable(); // how?
    $this->putJson($url, ['birthday' => true, 'customer_confirmed' => true, 'confirmation_method' => 'telepathy'])->assertUnprocessable();
    $this->putJson($url, ['birthday' => true, 'customer_confirmed' => true, 'confirmation_method' => 'other', 'confirmation_note' => str_repeat('x', 201)])->assertUnprocessable();
    expect(MessageConsent::query()->count())->toBe(0);

    $this->putJson($url, ['birthday' => true, 'customer_confirmed' => true, 'confirmation_method' => 'phone_call', 'confirmation_note' => ' Agreed on the call about TMB-26-00118 '])->assertOk()
        ->assertJsonPath('birthday.consented', true)->assertJsonPath('birthday.source', 'admin')
        ->assertJsonPath('birthday.recorded_by', ['id' => $staff->id, 'name' => 'Nadia Operator'])
        ->assertJsonPath('birthday.confirmation_method', 'phone_call')
        ->assertJsonPath('birthday.confirmation_note', 'Agreed on the call about TMB-26-00118')
        ->assertJsonPath('birthday.withdrawn_at', null);

    // Withdrawal needs no confirmation, keeps the row, when and how it was given.
    $this->putJson($url, ['birthday' => false])->assertOk()
        ->assertJsonPath('birthday.consented', false)->assertJsonPath('birthday.source', 'admin')
        ->assertJsonPath('birthday.confirmation_method', 'phone_call');
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
        ->assertJsonPath('birthday.consented', true)->assertJsonPath('birthday.source', 'storefront')->assertJsonPath('birthday.recorded_by', null)
        ->assertJsonPath('birthday.confirmation_method', null)->assertJsonPath('birthday.confirmation_note', null);
});

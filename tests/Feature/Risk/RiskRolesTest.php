<?php

declare(strict_types=1);

use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Database\Seeders\DeliverySeeder;
use Tests\Feature\Risk\RiskKit;
use Tests\Support\RoleMatrix;

/* The 403 role matrix for every risk endpoint (rule 21). */

beforeEach(fn () => $this->seed(DeliverySeeder::class));

it('guards GET /risk/queue', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/risk/queue'));
})->with(RoleMatrix::dataset(Permission::RiskView));

it('guards GET /risk/orders/{order}', function (Role $role, bool $allowed): void {
    $order = RiskKit::heldOrder();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/risk/orders/'.$order->id));
})->with(RoleMatrix::dataset(Permission::RiskView));

it('guards GET /risk/lookup', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/risk/lookup?phone=01712345678'));
})->with(RoleMatrix::dataset(Permission::RiskView));

it('guards POST /risk/lookup/rescore', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/risk/lookup/rescore', ['phone' => '01712345678']));
})->with(RoleMatrix::dataset(Permission::RiskVerify));

it('guards POST /risk/orders/{order}/verify (risk.verify + orders.confirm)', function (Role $role, bool $allowed): void {
    $order = RiskKit::heldOrder();
    $allowed = RoleMatrix::allows($role, Permission::RiskVerify) && RoleMatrix::allows($role, Permission::OrdersConfirm);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson("/api/v1/admin/risk/orders/{$order->id}/verify"));
})->with(RoleMatrix::dataset(Permission::RiskVerify));

it('guards POST /risk/orders/{order}/calls', function (Role $role, bool $allowed): void {
    $order = RiskKit::heldOrder();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson("/api/v1/admin/risk/orders/{$order->id}/calls", ['outcome' => 'confirmed']), 201);
})->with(RoleMatrix::dataset(Permission::RiskVerify));

it('guards POST /risk/orders/{order}/reject (risk.verify + orders.cancel)', function (Role $role, bool $allowed): void {
    $order = RiskKit::heldOrder();
    $allowed = RoleMatrix::allows($role, Permission::RiskVerify) && RoleMatrix::allows($role, Permission::OrdersCancel);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson("/api/v1/admin/risk/orders/{$order->id}/reject", ['reason' => 'fake_order']));
})->with(RoleMatrix::dataset(Permission::RiskVerify));

it('guards PUT /risk/phone-status', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson('/api/v1/admin/risk/phone-status', ['phone' => '01712345678', 'status' => 'watch', 'reason' => 'নজরে রাখুন']));
})->with(RoleMatrix::dataset(Permission::RiskManageLists));

it('guards GET /risk/rules', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/risk/rules'));
})->with(RoleMatrix::dataset(Permission::RiskView));

it('guards PUT /risk/rules', function (Role $role, bool $allowed): void {
    $body = [
        'weights' => ['cancellation_rate' => 25, 'courier_return_rate' => 25, 'failed_delivery' => 15, 'first_order' => 10, 'value_above_norm' => 10, 'address_quality' => 5, 'rapid_repeat' => 5],
        'medium_threshold' => 40, 'high_threshold' => 70, 'first_order_hold_threshold' => '3000.00', 'value_norm_multiplier' => '2', 'rapid_repeat_minutes' => 10,
    ];
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson('/api/v1/admin/risk/rules', $body));
})->with(RoleMatrix::dataset(Permission::RiskManageRules));

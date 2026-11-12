<?php

declare(strict_types=1);

use App\Domain\Ordering\Enums\ReturnStatus;
use App\Domain\Ordering\Models\OrderReturn;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\Refund;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Returns\ReturnsKit;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for every returns & refunds endpoint (CLAUDE.md rule 21). Each allowed
 * role works on a fresh return / refund, so a state change by one role never turns the
 * next role's 2xx into a 409.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'returns.approval_threshold' => '2000.00']);

    $this->variant = ReturnsKit::variant(100, '500.00', '300.00');
    $this->order = ReturnsKit::deliveredOrder([$this->variant->id => 60]);
    $this->line = $this->order->items->first();
});

function rrMatrixReturn(object $test, ReturnStatus $status = ReturnStatus::Requested): OrderReturn
{
    $return = OrderReturn::query()->create([
        'number' => 'RMA-T-'.Str::random(8), 'order_id' => $test->order->id, 'status' => $status, 'reason' => 'defective',
    ]);
    $return->items()->create(['order_item_id' => $test->line->id, 'quantity' => 1]);

    return $return;
}

function rrMatrixRefund(object $test, string $amount, RefundStatus $status = RefundStatus::Requested): Refund
{
    return Refund::query()->create([
        'order_id' => $test->order->id, 'payment_id' => $test->order->payments()->value('id'), 'amount' => $amount,
        'reason' => 'matrix', 'type' => 'partial', 'method' => 'cod', 'status' => $status,
    ]);
}

/** Roles holding either permission. */
function rrEitherDataset(Permission $a, Permission $b): array
{
    $rows = [];
    foreach (Role::cases() as $role) {
        $allowed = RoleMatrix::allows($role, $a) || RoleMatrix::allows($role, $b);
        $rows[$role->value.($allowed ? ' is allowed' : ' is forbidden')] = [$role, $allowed];
    }

    return $rows;
}

it('guards the returns read endpoints with returns.view', function (): void {
    $return = rrMatrixReturn($this);
    RoleMatrix::assert($this, Permission::ReturnsView, fn () => $this->getJson('/api/v1/admin/returns'));
    RoleMatrix::assert($this, Permission::ReturnsView, fn () => $this->getJson('/api/v1/admin/returns/'.$return->id));
    RoleMatrix::assert($this, Permission::ReturnsView, fn () => $this->getJson("/api/v1/admin/orders/{$this->order->id}/returns"));
});

it('guards opening a return with returns.manage', function (): void {
    RoleMatrix::assert($this, Permission::ReturnsManage, fn () => $this->postJson("/api/v1/admin/orders/{$this->order->id}/returns", [
        'items' => [['order_item_id' => $this->line->id, 'quantity' => 1]], 'reason' => 'defective',
    ]), 201);
});

it('guards receive, inspect and reject with returns.manage', function (string $step): void {
    RoleMatrix::assert($this, Permission::ReturnsManage, function () use ($step) {
        $return = rrMatrixReturn($this);

        return $this->postJson("/api/v1/admin/returns/{$return->id}/{$step}", match ($step) {
            'inspect' => ['items' => [['order_item_id' => $this->line->id, 'resellable' => 1, 'damaged' => 0]]],
            'reject' => ['note' => 'Never sent back'],
            default => [],
        });
    });
})->with(['receive', 'inspect', 'reject']);

it('lets refunds.create or refunds.approve approve at or below the threshold', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/refunds/'.rrMatrixRefund($this, '500.00')->id.'/approve'));
})->with(rrEitherDataset(Permission::RefundsCreate, Permission::RefundsApprove));

it('needs refunds.approve above the threshold — to approve or to reject', function (string $step): void {
    RoleMatrix::assert($this, Permission::RefundsApprove, fn () => $this->postJson(
        '/api/v1/admin/refunds/'.rrMatrixRefund($this, '2500.00')->id.'/'.$step,
        $step === 'reject' ? ['note' => 'Outside policy'] : [],
    ));
})->with(['approve', 'reject']);

it('lets refunds.create or refunds.approve pay an approved refund out', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(
        '/api/v1/admin/refunds/'.rrMatrixRefund($this, '100.00', RefundStatus::Approved)->id.'/pay',
        ['method' => 'nagad', 'reference' => 'NG123'],
        ['Idempotency-Key' => (string) Str::uuid()],
    ));
})->with(rrEitherDataset(Permission::RefundsCreate, Permission::RefundsApprove));

it('refuses a guest with 401 on every returns endpoint', function (string $method, string $uri): void {
    $this->json($method, '/api/v1/admin/'.$uri, [], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/unauthenticated');
})->with([
    ['GET', 'returns'],
    ['GET', 'returns/1'],
    ['POST', 'returns/1/receive'],
    ['POST', 'returns/1/inspect'],
    ['POST', 'returns/1/reject'],
    ['GET', 'orders/1/returns'],
    ['POST', 'orders/1/returns'],
    ['POST', 'refunds/1/approve'],
    ['POST', 'refunds/1/reject'],
    ['POST', 'refunds/1/pay'],
]);

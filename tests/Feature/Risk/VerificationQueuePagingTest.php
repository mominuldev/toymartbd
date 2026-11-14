<?php

declare(strict_types=1);

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\VerificationStatus;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Api\V1\Admin\RiskQueueController;
use App\Models\Enums\Role;
use Tests\Feature\Finance\FinanceKit;

// Phase 9 (docs/audits/PERFORMANCE.md P-09): the queue's rows are HeldOrder objects, and the cursor
// paginator reads (placed_at, id) off the last one. A queue longer than one page used to 500.

it('pages a verification queue longer than one page', function (): void {
    FinanceKit::actingAs(Role::OrderOperator);
    $total = RiskQueueController::PER_PAGE + 2;
    for ($i = 0; $i < $total; $i++) {
        Order::factory()->create([
            'status' => OrderStatus::OnHold, 'verification_status' => VerificationStatus::Pending,
            'risk_level' => 'high', 'placed_at' => now()->subMinutes($total - $i),
        ]);
    }

    $first = $this->getJson('/api/v1/admin/risk/queue')->assertOk();
    expect($first->json('data'))->toHaveCount(RiskQueueController::PER_PAGE)
        ->and($first->json('meta.count'))->toBe($total)
        ->and($first->json('meta.next_cursor'))->toBeString();

    $second = $this->getJson('/api/v1/admin/risk/queue?cursor='.$first->json('meta.next_cursor'))->assertOk();
    expect($second->json('data'))->toHaveCount(2)
        ->and(array_intersect(array_column($first->json('data'), 'number'), array_column($second->json('data'), 'number')))->toBe([]);
});

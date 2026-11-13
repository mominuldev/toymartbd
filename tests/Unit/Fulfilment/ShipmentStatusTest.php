<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Drivers\NullCourierGateway;
use App\Domain\Fulfilment\Enums\ShipmentStatus as S;

/*
 * The shipment state machine (docs/api/shipments.md): every (from, to) pair is either in the
 * table or refused, terminal states have no way out, and nothing moves backwards out of an
 * outcome.
 */

it('allows exactly the listed moves', function (S $from, S $to): void {
    $allowed = [
        'pending' => ['submitted', 'booked', 'failed', 'cancelled'],
        'failed' => ['pending', 'cancelled'],
        'submitted' => ['booked', 'picked_up', 'in_transit', 'out_for_delivery', 'on_hold', 'failed', 'cancelled', 'delivered', 'partially_delivered', 'returned'],
        'booked' => ['picked_up', 'in_transit', 'out_for_delivery', 'on_hold', 'cancelled', 'delivered', 'partially_delivered', 'returned'],
        'picked_up' => ['in_transit', 'out_for_delivery', 'on_hold', 'delivered', 'partially_delivered', 'returned'],
        'in_transit' => ['out_for_delivery', 'on_hold', 'delivered', 'partially_delivered', 'returned'],
        'out_for_delivery' => ['in_transit', 'on_hold', 'delivered', 'partially_delivered', 'returned'],
        'on_hold' => ['booked', 'picked_up', 'in_transit', 'out_for_delivery', 'cancelled', 'delivered', 'partially_delivered', 'returned'],
        'partially_delivered' => ['returned'],
        'delivered' => [],
        'returned' => [],
        'cancelled' => [],
    ];

    expect($from->canTransitionTo($to))->toBe(in_array($to->value, $allowed[$from->value], true));
})->with(fn (): array => array_merge(...array_map(
    fn (S $from): array => array_combine(
        array_map(fn (S $to): string => "{$from->value} → {$to->value}", S::cases()),
        array_map(fn (S $to): array => [$from, $to], S::cases()),
    ),
    S::cases(),
)));

it('treats only failed and cancelled as freeing the order for a new shipment', function (): void {
    $closed = array_values(array_filter(S::cases(), fn (S $s): bool => ! $s->isOpen()));

    expect($closed)->toBe([S::Cancelled, S::Failed]);
});

it('maps every fixture driver status to a normalised one', function (): void {
    foreach (NullCourierGateway::statusMap() as $raw => $status) {
        expect($raw)->toBeString()->and($status)->toBeInstanceOf(S::class);
    }
    expect(array_values(array_unique(array_map(fn (S $s): string => $s->value, NullCourierGateway::statusMap()))))->toContain('delivered', 'returned', 'partially_delivered');
});

<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketing\Contracts\CustomerOrderHistory;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Queries\PhoneOrderHistory;
use App\Support\PhoneNumber;

it('binds the Marketing order-history seam to real orders', function (): void {
    expect(app(CustomerOrderHistory::class))->toBeInstanceOf(PhoneOrderHistory::class);
});

it('answers whether a phone has a non-cancelled order, however the number is typed', function (): void {
    $history = app(CustomerOrderHistory::class);
    $customer = Customer::factory()->create(['phone' => '+8801712345678', 'name' => 'রহিম উদ্দিন']);

    expect($history->hasOrdered(PhoneNumber::parse('01712345678')))->toBeFalse();

    Order::factory()->for($customer)->status(OrderStatus::Cancelled)->create();
    expect($history->hasOrdered(PhoneNumber::parse('01712345678')))->toBeFalse();

    Order::factory()->for($customer)->create();
    expect($history->hasOrdered(PhoneNumber::parse('+880 1712-345678')))->toBeTrue()
        ->and($history->hasOrdered(PhoneNumber::parse('01812345678')))->toBeFalse();
});

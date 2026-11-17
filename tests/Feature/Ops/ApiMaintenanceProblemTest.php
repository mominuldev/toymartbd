<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Exceptions\DeliveryQuoteUnavailable;
use Illuminate\Support\Facades\Route;

/*
| Gate 5 "should close": during planned downtime the shop shows its maintenance page, not errors.
| The storefront tells `php artisan down` apart from any other 503 by the problem `type`, and
| keeps the page up for as long as `Retry-After` says (toymartbd-storefront/lib/maintenance.ts).
*/

it('answers `maintenance` with the Retry-After from `artisan down --retry`', function (): void {
    $this->app->maintenanceMode()->activate(['retry' => 120]);

    try {
        $response = $this->getJson('/api/v1/storefront/brands');

        $response->assertStatus(503)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Retry-After', '120')
            ->assertJsonPath('type', 'https://toymartbd.com/problems/maintenance')
            ->assertJsonPath('status', 503);
    } finally {
        $this->app->maintenanceMode()->deactivate();
    }
});

it('keeps a feature-level 503 as `service-unavailable`, never `maintenance`', function (): void {
    Route::middleware('api')->get('/api/v1/storefront/_test/no-quote', fn () => throw DeliveryQuoteUnavailable::noProvider());

    $this->getJson('/api/v1/storefront/_test/no-quote')
        ->assertStatus(503)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/service-unavailable');
});

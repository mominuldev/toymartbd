<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Models\CourierProvider;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
});

it('verifies and unverifies a courier provider and never prints credentials', function (): void {
    $provider = CourierProvider::query()->where('code', 'steadfast')->firstOrFail();
    $provider->forceFill(['verified' => false, 'credentials' => ['api_key' => 'sf-key-123456', 'secret_key' => 'sf-secret-abcdef']])->save();

    $this->artisan('toymart:couriers:provider', ['code' => 'steadfast', '--verify' => true])
        ->doesntExpectOutputToContain('sf-secret-abcdef')
        ->doesntExpectOutputToContain('sf-key-123456')
        ->assertSuccessful();
    expect($provider->fresh()->verified)->toBeTrue();

    $this->artisan('toymart:couriers:provider', ['code' => 'steadfast', '--unverify' => true])->assertSuccessful();
    expect($provider->fresh()->verified)->toBeFalse();
});

it('refuses an unknown provider and conflicting flags', function (): void {
    $this->artisan('toymart:couriers:provider', ['code' => 'nope'])->assertFailed();
    $this->artisan('toymart:couriers:provider', ['code' => 'pathao', '--verify' => true, '--unverify' => true])->assertExitCode(2);
});

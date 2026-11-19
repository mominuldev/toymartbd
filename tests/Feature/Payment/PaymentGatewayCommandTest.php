<?php

declare(strict_types=1);

use App\Domain\Payment\Models\PaymentProvider;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Payments\FakePaystationGateway;
use Tests\Support\Payments\FakeSslcommerzGateway;

/*
 * `toymart:payments:gateway {code}` — generic over gateway codes. `--live` is refused while
 * the live key set is incomplete (env never supplies a live key), the table shows the mode,
 * and no credential is ever printed.
 */

beforeEach(function (): void {
    (new FakeSslcommerzGateway)->register();
    (new FakePaystationGateway)->register();
});

dataset('cli managed gateways', [
    'sslcommerz' => ['sslcommerz', ['store_id' => 'cli-store-SECRETID', 'store_password' => 'cli-pass-SECRET-1']],
    'paystation' => ['paystation', ['merchant_id' => 'cli-merchant-SECRETID', 'password' => 'cli-pass-SECRET-2']],
]);

it('refuses --live without a complete live key set and changes nothing', function (string $code, array $keys): void {
    $row = PaymentProvider::query()->where('code', $code)->firstOrFail();
    // Only the sandbox set, and a half live set: neither may switch to live.
    $row->forceFill(['sandbox' => true, 'is_active' => false, 'credentials' => ['sandbox' => $keys, 'live' => array_slice($keys, 0, 1, true)]])->save();

    expect(Artisan::call('toymart:payments:gateway', ['code' => $code, '--live' => true, '--activate' => true]))->toBe(1);
    expect(Artisan::output())->toContain('Refusing --live')->toContain($code)->not->toContain('SECRET');

    $row->refresh();
    expect($row->sandbox)->toBeTrue()->and($row->is_active)->toBeFalse();
})->with('cli managed gateways');

it('switches to live once the live keys are saved, and shows the mode', function (string $code, array $keys): void {
    $row = PaymentProvider::query()->where('code', $code)->firstOrFail();
    $row->forceFill(['sandbox' => true, 'credentials' => ['live' => $keys]])->save();

    expect(Artisan::call('toymart:payments:gateway', ['code' => $code, '--live' => true, '--verify' => true]))->toBe(0);
    $out = Artisan::output();
    expect($out)->toContain('mode')->toContain('live')->not->toContain('SECRET');
    expect($row->refresh()->sandbox)->toBeFalse()->and($row->verified)->toBeTrue();

    expect(Artisan::call('toymart:payments:gateway', ['code' => $code, '--sandbox' => true]))->toBe(0);
    expect(Artisan::output())->toContain('sandbox');
    expect($row->refresh()->sandbox)->toBeTrue();
})->with('cli managed gateways');

it('refuses --live together with --sandbox, and an unknown code', function (): void {
    expect(Artisan::call('toymart:payments:gateway', ['code' => 'paystation', '--live' => true, '--sandbox' => true]))->toBe(1)
        ->and(Artisan::call('toymart:payments:gateway', ['code' => 'nope']))->toBe(1);
});

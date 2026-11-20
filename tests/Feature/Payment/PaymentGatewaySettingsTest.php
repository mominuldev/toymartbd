<?php

declare(strict_types=1);

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Gateways\CashOnDeliveryGateway;
use App\Domain\Payment\Gateways\Sslcommerz\SslcommerzCredentials;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Services\PaymentGatewayRegistry;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\RoleMatrix;

/*
 * Integrations › Payments (owner request 2026-11-19): SSLCommerz and PayStation, a sandbox and
 * a live key set each, the mode in use, on/off and "Check connection". Secret keys are
 * write-only (rule 20); changes are audited by field name only; the network is never called.
 */

const PGS_SANDBOX_SECRET = 'sbx-secret-7f3a9c11';
const PGS_LIVE_SECRET = 'live-secret-5e2b8d44';

beforeEach(function (): void {
    Http::preventStrayRequests();
    (new RolesAndPermissionsSeeder)->run();
    config([
        'payments.sslcommerz.store_id' => null,
        'payments.sslcommerz.store_password' => null,
        'payments.paystation.merchant_id' => null,
        'payments.paystation.password' => null,
    ]);
});

function gatewayAdmin(Role $role = Role::SuperAdmin): User
{
    $user = User::factory()->withRole($role)->create()->refresh();
    test()->actingAs($user);

    return $user;
}

function sslRow(): PaymentProvider
{
    return PaymentProvider::query()->where('code', 'sslcommerz')->firstOrFail();
}

/** @param  array<string, mixed>  $credentials */
function storeSsl(array $credentials, array $attributes = []): PaymentProvider
{
    $row = sslRow();
    $row->forceFill(['credentials' => $credentials] + $attributes)->save();

    return $row->refresh();
}

describe('role matrix (rule 21)', function (): void {
    it('guards GET /settings/payment-gateways', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/settings/payment-gateways'));
    })->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));

    it('guards PUT /settings/payment-gateways/{code}', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', [
            'credentials' => ['sandbox' => ['store_id' => 'store-'.$role->value]],
        ]));
    })->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));

    it('guards POST /settings/payment-gateways/{code}/check', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/settings/payment-gateways/sslcommerz/check'));
    })->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));
});

describe('GET /settings/payment-gateways', function (): void {
    it('lists every managed gateway with mode, status and per-mode fields — never a secret', function (): void {
        storeSsl([
            'sandbox' => ['store_id' => 'sbx-store', 'store_password' => PGS_SANDBOX_SECRET],
            'live' => ['store_id' => 'live-store', 'store_password' => PGS_LIVE_SECRET],
        ], ['sandbox' => false, 'is_active' => true]);
        gatewayAdmin();

        $response = $this->getJson('/api/v1/admin/settings/payment-gateways')->assertOk();

        expect($response->getContent())->not->toContain(PGS_SANDBOX_SECRET)->not->toContain(PGS_LIVE_SECRET);
        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'sslcommerz')
            ->assertJsonPath('data.0.mode', 'live')
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonPath('data.0.verified', false)
            ->assertJsonPath('data.0.configured', true)
            ->assertJsonPath('data.0.status.enabled', true)
            ->assertJsonPath('data.0.credentials.live.complete', true)
            ->assertJsonPath('data.0.credentials.live.fields.0', ['key' => 'store_id', 'secret' => false, 'set' => true, 'value' => 'live-store'])
            ->assertJsonPath('data.0.credentials.live.fields.1', ['key' => 'store_password', 'secret' => true, 'set' => true, 'value' => null])
            ->assertJsonPath('data.0.credentials.sandbox.fields.0.value', 'sbx-store')
            ->assertJsonPath('data.1.code', 'paystation')
            ->assertJsonPath('data.1.mode', 'sandbox')
            ->assertJsonPath('data.1.configured', false)
            ->assertJsonPath('data.1.credentials.live.complete', false);
    });

    it('gives each gateway its IPN URL and the host per mode, for display', function (): void {
        gatewayAdmin();

        $this->getJson('/api/v1/admin/settings/payment-gateways')
            ->assertOk()
            ->assertJsonPath('data.0.ipn_url', route('api.v1.webhooks.payments.sslcommerz.ipn'))
            ->assertJsonPath('data.0.hosts', ['sandbox' => 'sandbox.sslcommerz.com', 'live' => 'securepay.sslcommerz.com'])
            ->assertJsonPath('data.1.ipn_url', route('api.v1.webhooks.payments.paystation.ipn'))
            ->assertJsonPath('data.1.hosts', ['sandbox' => 'sandbox.paystation.com.bd', 'live' => 'api.paystation.com.bd']);
        expect(route('api.v1.webhooks.payments.sslcommerz.ipn'))->toEndWith('/api/v1/webhooks/payments/sslcommerz/ipn');
    });

    it('says when the settings were last saved and by whom — from the audit row, null until the first save', function (): void {
        $user = gatewayAdmin();

        $this->getJson('/api/v1/admin/settings/payment-gateways')
            ->assertOk()
            ->assertJsonPath('data.0.updated_at', null)
            ->assertJsonPath('data.0.updated_by', null);

        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', ['credentials' => ['sandbox' => ['store_id' => 'sbx-store']]])
            ->assertOk()
            ->assertJsonPath('updated_by', ['name' => $user->name]);
        $audit = AuditSensitive::query()->where('action', AuditAction::CredentialChanged->value)->latest('id')->firstOrFail();

        $this->getJson('/api/v1/admin/settings/payment-gateways')
            ->assertOk()
            ->assertJsonPath('data.0.updated_at', $audit->created_at->toIso8601String())
            ->assertJsonPath('data.0.updated_by', ['name' => $user->name])
            ->assertJsonPath('data.1.updated_at', null);
    });

    it('in production, a live gateway that is not verified saves but says it takes no payment', function (): void {
        storeSsl(['live' => ['store_id' => 'live-store', 'store_password' => PGS_LIVE_SECRET]], ['sandbox' => false, 'is_active' => true]);
        gatewayAdmin();
        app()->detectEnvironment(fn (): string => 'production');

        try {
            $this->getJson('/api/v1/admin/settings/payment-gateways')
                ->assertOk()
                ->assertJsonPath('data.0.status.enabled', false)
                ->assertJsonPath('data.0.status.reason', 'unverified');
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
    });
});

describe('PUT /settings/payment-gateways/{code}', function (): void {
    it('saves each mode\'s keys separately and keeps them encrypted at rest', function (): void {
        gatewayAdmin();

        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', [
            'credentials' => [
                'sandbox' => ['store_id' => ' sbx-store ', 'store_password' => PGS_SANDBOX_SECRET],
                'live' => ['store_id' => 'live-store', 'store_password' => PGS_LIVE_SECRET],
            ],
        ])->assertOk()
            ->assertJsonPath('credentials.sandbox.fields.0.value', 'sbx-store')
            ->assertJsonPath('credentials.live.complete', true)
            ->assertJsonMissing(['value' => PGS_LIVE_SECRET]);

        expect(sslRow()->credentials)->toBe([
            'sandbox' => ['store_id' => 'sbx-store', 'store_password' => PGS_SANDBOX_SECRET],
            'live' => ['store_id' => 'live-store', 'store_password' => PGS_LIVE_SECRET],
        ]);
        $raw = (string) DB::table('payment_gateways')->where('code', 'sslcommerz')->value('credentials');
        expect($raw)->not->toContain(PGS_LIVE_SECRET)->not->toContain(PGS_SANDBOX_SECRET);
    });

    it('keeps a stored secret when it is sent blank or null, and removes it only through clear', function (): void {
        storeSsl(['sandbox' => ['store_id' => 'sbx-store', 'store_password' => PGS_SANDBOX_SECRET]]);
        gatewayAdmin();

        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', [
            'credentials' => ['sandbox' => ['store_id' => 'sbx-store-2', 'store_password' => '']],
        ])->assertOk();
        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', [
            'credentials' => ['sandbox' => ['store_password' => null]],
        ])->assertOk();
        expect(sslRow()->credentials['sandbox'])->toBe(['store_id' => 'sbx-store-2', 'store_password' => PGS_SANDBOX_SECRET]);

        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', ['clear' => ['sandbox' => ['store_password']]])
            ->assertOk()
            ->assertJsonPath('credentials.sandbox.fields.1.set', false);
        expect(sslRow()->credentials['sandbox'])->toBe(['store_id' => 'sbx-store-2']);
    });

    it('clears verified when the mode switches or a live key changes, but not for a sandbox key edit', function (): void {
        $keys = [
            'sandbox' => ['store_id' => 'sbx-store', 'store_password' => PGS_SANDBOX_SECRET],
            'live' => ['store_id' => 'live-store', 'store_password' => PGS_LIVE_SECRET],
        ];
        $user = gatewayAdmin();

        storeSsl($keys, ['sandbox' => true, 'verified' => true, 'verified_at' => now()]);
        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', ['credentials' => ['sandbox' => ['store_id' => 'sbx-store-2']]])
            ->assertOk()->assertJsonPath('verified', true);

        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', ['mode' => 'live'])
            ->assertOk()->assertJsonPath('verified', false)->assertJsonPath('verified_at', null);
        $audit = AuditSensitive::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
        expect($audit->after['verified'] ?? null)->toBe(['from' => true, 'to' => false]);

        storeSsl($keys, ['sandbox' => false, 'verified' => true, 'verified_at' => now()]);
        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', ['credentials' => ['live' => ['store_id' => 'live-store-2']]])
            ->assertOk()->assertJsonPath('verified', false);
    });

    it('refuses an unknown key, a key both set and cleared, and an unknown gateway', function (): void {
        gatewayAdmin();

        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', ['credentials' => ['live' => ['api_key' => 'x']]])
            ->assertStatus(422)->assertJsonPath('type', 'https://toymartbd.com/problems/validation-error');
        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', [
            'credentials' => ['live' => ['store_id' => 'x']], 'clear' => ['live' => ['store_id']],
        ])->assertStatus(422);
        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', ['credentials' => ['production' => ['store_id' => 'x']]])
            ->assertStatus(422);
        // Values are checked in after() (the OpenAPI shape stays a key → value map): too long or not text.
        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', ['credentials' => ['live' => ['store_id' => str_repeat('x', 501)]]])
            ->assertStatus(422)->assertJsonPath('errors.0.field', 'credentials.live.store_id');
        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', ['credentials' => ['sandbox' => ['store_id' => ['nested']]]])
            ->assertStatus(422)->assertJsonPath('errors.0.field', 'credentials.sandbox.store_id');
        expect(sslRow()->refresh()->credentials)->toBeNull();
        $this->putJson('/api/v1/admin/settings/payment-gateways/bkash', ['is_active' => true])
            ->assertNotFound()->assertJsonPath('type', 'https://toymartbd.com/problems/not-found');
        $this->postJson('/api/v1/admin/settings/payment-gateways/cod/check')->assertNotFound();
    });

    it('refuses to switch to live without a complete live set; env never fills it', function (): void {
        config(['payments.sslcommerz.store_id' => 'env-store', 'payments.sslcommerz.store_password' => 'env-secret']);
        storeSsl(['live' => ['store_id' => 'live-store']]);
        gatewayAdmin();

        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', ['mode' => 'live'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'mode');
        expect(sslRow()->sandbox)->toBeTrue();

        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', [
            'mode' => 'live', 'credentials' => ['live' => ['store_password' => PGS_LIVE_SECRET]],
        ])->assertOk()->assertJsonPath('mode', 'live');
        expect(sslRow()->sandbox)->toBeFalse();
    });

    it('refuses to switch a gateway on without a complete set for its mode', function (): void {
        gatewayAdmin();

        $this->putJson('/api/v1/admin/settings/payment-gateways/paystation', ['is_active' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'is_active');
        expect(PaymentProvider::forCode('paystation')?->is_active)->toBeFalse();

        // The sandbox set may lean on env values.
        config(['payments.sslcommerz.store_id' => 'env-store', 'payments.sslcommerz.store_password' => 'env-secret']);
        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', ['is_active' => true])
            ->assertOk()->assertJsonPath('is_active', true);

        // …and an active gateway cannot lose a key its mode needs.
        storeSsl(['live' => ['store_id' => 'live-store', 'store_password' => PGS_LIVE_SECRET]], ['sandbox' => false, 'is_active' => true]);
        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', ['clear' => ['live' => ['store_password']]])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'credentials.live');
    });

    it('audits which fields and switches changed — never a value', function (): void {
        $user = gatewayAdmin();

        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', [
            'mode' => 'live',
            'is_active' => true,
            'credentials' => ['live' => ['store_id' => 'live-store', 'store_password' => PGS_LIVE_SECRET]],
        ])->assertOk();
        // Nothing changes: no second row.
        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', ['credentials' => ['live' => ['store_id' => 'live-store']]])->assertOk();

        $audits = AuditSensitive::query()->where('action', AuditAction::CredentialChanged)->get();
        expect($audits)->toHaveCount(1);
        $audit = $audits->first();
        expect($audit->user_id)->toBe($user->id)
            ->and($audit->model_id)->toBe(sslRow()->id)
            ->and($audit->after)->toBe([
                'gateway' => 'sslcommerz',
                'changed' => ['live.store_id', 'live.store_password'],
                'mode' => ['from' => 'sandbox', 'to' => 'live'],
                'is_active' => ['from' => false, 'to' => true],
            ])
            ->and(json_encode($audit->getAttributes()))->not->toContain(PGS_LIVE_SECRET)->not->toContain('live-store');
    });
});

describe('POST /settings/payment-gateways/{code}/check', function (): void {
    it('maps the SSLCommerz Transaction Query answer for the current mode', function (string $apiConnect, bool $ok, string $code): void {
        storeSsl(['live' => ['store_id' => 'live-store', 'store_password' => PGS_LIVE_SECRET]], ['sandbox' => false]);
        gatewayAdmin();
        Http::fake(['https://securepay.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(['APIConnect' => $apiConnect, 'no_of_trans_found' => 0, 'element' => []])]);

        $this->postJson('/api/v1/admin/settings/payment-gateways/sslcommerz/check')
            ->assertOk()
            ->assertJsonPath('ok', $ok)
            ->assertJsonPath('code', $code)
            ->assertJsonPath('mode', 'live')
            ->assertJsonMissing(['store_passwd' => PGS_LIVE_SECRET]);

        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://securepay.sslcommerz.com/')
            && $r['store_id'] === 'live-store' && str_starts_with((string) $r['tran_id'], 'TMB-CHECK-'));
        expect(sslRow()->verified)->toBeFalse();
    })->with([
        'DONE' => ['DONE', true, 'ok'],
        'FAILED' => ['FAILED', false, 'credentials_rejected'],
        'INACTIVE' => ['INACTIVE', false, 'credentials_rejected'],
        'INVALID_REQUEST' => ['INVALID_REQUEST', false, 'credentials_rejected'],
        'anything else' => ['MAYBE', false, 'unreachable'],
    ]);

    it('is not_configured without keys and asks nothing; a 5xx, garbage or timeout is unreachable', function (): void {
        gatewayAdmin();
        $this->postJson('/api/v1/admin/settings/payment-gateways/sslcommerz/check')
            ->assertOk()->assertJsonPath('code', 'not_configured')->assertJsonPath('mode', 'sandbox');
        Http::assertNothingSent();

        storeSsl(['sandbox' => ['store_id' => 'sbx-store', 'store_password' => PGS_SANDBOX_SECRET]]);
        Http::fake(['https://sandbox.sslcommerz.com/*' => Http::sequence()
            ->push('oops', 503)
            ->push('<html>not json</html>', 200),
        ]);
        foreach (range(1, 2) as $_) {
            $this->postJson('/api/v1/admin/settings/payment-gateways/sslcommerz/check')->assertOk()->assertJsonPath('code', 'unreachable');
        }
    });

    it('a transport failure is unreachable and leaks nothing', function (): void {
        storeSsl(['sandbox' => ['store_id' => 'sbx-store', 'store_password' => PGS_SANDBOX_SECRET]]);
        gatewayAdmin();
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://sandbox.sslcommerz.com/x?store_passwd='.PGS_SANDBOX_SECRET));

        $response = $this->postJson('/api/v1/admin/settings/payment-gateways/sslcommerz/check')->assertOk()->assertJsonPath('code', 'unreachable');
        expect($response->getContent())->not->toContain(PGS_SANDBOX_SECRET);
    });

    it('answers 422 connection-check-unsupported when the driver cannot check its keys', function (): void {
        app(PaymentGatewayRegistry::class)->register(PaymentMethod::Paystation, CashOnDeliveryGateway::class);
        gatewayAdmin();

        $this->postJson('/api/v1/admin/settings/payment-gateways/paystation/check')
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://toymartbd.com/problems/connection-check-unsupported');
    });
});

describe('SslcommerzCredentials (per-mode keys)', function (): void {
    it('picks the sandbox or live set, and its host, from the row mode', function (): void {
        storeSsl([
            'sandbox' => ['store_id' => 'sbx-store', 'store_password' => PGS_SANDBOX_SECRET],
            'live' => ['store_id' => 'live-store', 'store_password' => PGS_LIVE_SECRET],
        ]);
        $sandbox = SslcommerzCredentials::resolve();
        expect($sandbox?->storeId)->toBe('sbx-store')
            ->and($sandbox?->sandbox)->toBeTrue()
            ->and($sandbox?->host())->toBe('https://sandbox.sslcommerz.com');

        sslRow()->forceFill(['sandbox' => false])->save();
        $live = SslcommerzCredentials::resolve();
        expect($live?->storeId)->toBe('live-store')
            ->and($live?->storePassword)->toBe(PGS_LIVE_SECRET)
            ->and($live?->sandbox)->toBeFalse()
            ->and($live?->host())->toBe('https://securepay.sslcommerz.com');
    });

    it('fills sandbox gaps from env but never the live set', function (): void {
        config(['payments.sslcommerz.store_id' => 'env-store', 'payments.sslcommerz.store_password' => 'env-secret']);
        storeSsl(['sandbox' => ['store_id' => 'sbx-store']]);
        expect(SslcommerzCredentials::resolve()?->storeId)->toBe('sbx-store')
            ->and(SslcommerzCredentials::resolve()?->storePassword)->toBe('env-secret');

        sslRow()->forceFill(['sandbox' => false])->save();
        expect(SslcommerzCredentials::resolve())->toBeNull();
    });

    it('still reads legacy flat credentials as the sandbox set', function (): void {
        storeSsl(['store_id' => 'old-store', 'store_password' => 'old-secret']);
        expect(SslcommerzCredentials::resolve()?->storeId)->toBe('old-store')
            ->and(SslcommerzCredentials::resolve()?->sandbox)->toBeTrue();

        sslRow()->forceFill(['sandbox' => false])->save();
        expect(SslcommerzCredentials::resolve())->toBeNull();
    });

    it('rewrites legacy flat credentials nested on the next save', function (): void {
        storeSsl(['store_id' => 'old-store', 'store_password' => 'old-secret']);
        gatewayAdmin();

        $this->putJson('/api/v1/admin/settings/payment-gateways/sslcommerz', ['credentials' => ['live' => ['store_id' => 'live-store']]])
            ->assertOk()
            ->assertJsonPath('credentials.sandbox.complete', true);
        expect(sslRow()->credentials)->toBe([
            'sandbox' => ['store_id' => 'old-store', 'store_password' => 'old-secret'],
            'live' => ['store_id' => 'live-store'],
        ]);
    });
});

<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Support\GatewayCredentials;
use App\Domain\Payment\Support\GatewayEnvFallback;
use App\Http\Resources\ApiResource;
use App\Models\AuditSensitive;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * One online gateway on Integrations › Payments (owner request 2026-11-19). A secret key is
 * NEVER returned, not even masked — only whether it is set (rule 20). Non-secret keys (a store
 * or merchant id) are returned so the owner can see which account is connected.
 *
 * `status` is what checkout sees right now ({@see \App\Domain\Payment\Services\OnlineGateways}):
 * in production a live gateway that is not `verified` saves fine but takes no payment
 * (`reason: unverified`) until the owner verifies it from the CLI.
 *
 * `ipn_url`, `hosts` and `updated_*` are display-only (redesign 2026-11-20): the IPN address the
 * owner pastes into the gateway's panel, the host each mode talks to, and who saved last — read
 * from the `credential_changed` audit row that every save writes, never a second record.
 *
 * @property array{gateway: PaymentProvider, method: PaymentMethod, status: array{enabled: bool, reason: string|null}, last_saved?: AuditSensitive|null} $resource
 */
#[SchemaName('PaymentGatewaySettings')]
final class PaymentGatewayResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource['gateway'];
        $method = $this->resource['method'];
        $status = $this->resource['status'];
        $env = GatewayEnvFallback::for($method);
        $saved = $this->resource['last_saved'] ?? null;
        $ipnRoute = 'api.v1.webhooks.payments.'.$row->code.'.ipn';

        return [
            /** @var 'sslcommerz'|'paystation'|'bkash' */
            'code' => $row->code,
            'name' => $row->name,
            /** @var bool */
            'is_active' => $row->is_active,
            /** @var 'sandbox'|'live' The key set in use. */
            'mode' => GatewayCredentials::mode($row->sandbox),
            /** @var bool A real payment has been proven through this gateway; set by the owner's CLI only. */
            'verified' => $row->verified,
            /** @var string|null */
            'verified_at' => $row->verified_at?->toIso8601String(),
            /** @var bool Every key the current mode needs is available (sandbox may use env values). */
            'configured' => GatewayCredentials::complete($method, GatewayCredentials::forMode($row, $row->sandbox, $env)),
            /** @var array{enabled: bool, reason: 'not_registered'|'inactive'|'not_configured'|'unverified'|'sandbox_in_production'|null} Whether checkout offers it now, and why not. */
            'status' => [
                'enabled' => $status['enabled'],
                'reason' => $status['reason'],
            ],
            /** @var array{sandbox: array{complete: bool, fields: list<array{key: string, secret: bool, set: bool, value: string|null, hint: string|null}>}, live: array{complete: bool, fields: list<array{key: string, secret: bool, set: bool, value: string|null, hint: string|null}>}} Saved keys per mode. `value` is null for every secret; `hint` is "••••1234" at most, only for a saved secret of a gateway that shows one (bKash). `complete` counts saved keys only. */
            'credentials' => [
                GatewayCredentials::SANDBOX => self::set($method, $row, true),
                GatewayCredentials::LIVE => self::set($method, $row, false),
            ],
            /** @var string|null Absolute URL of this gateway's IPN webhook — what the owner gives the gateway. Null when it has none. */
            'ipn_url' => Route::has($ipnRoute) ? route($ipnRoute) : null,
            /** @var array{sandbox: string|null, live: string|null} The host each mode talks to, for display only (config/payments.php). */
            'hosts' => [
                GatewayCredentials::SANDBOX => self::host($row->code, GatewayCredentials::SANDBOX),
                GatewayCredentials::LIVE => self::host($row->code, GatewayCredentials::LIVE),
            ],
            /** @var string|null When these settings were last saved on Integrations › Payments; null if never. */
            'updated_at' => $saved?->created_at->toIso8601String(),
            /** @var array{name: string}|null Who saved them last; null if never saved here, or saved by a console command. */
            'updated_by' => $saved?->user === null ? null : ['name' => $saved->user->name],
        ];
    }

    private static function host(string $code, string $mode): ?string
    {
        $url = config("payments.{$code}.hosts.{$mode}");
        $host = is_string($url) && $url !== '' ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? $host : null;
    }

    /** @return array{complete: bool, fields: list<array{key: string, secret: bool, set: bool, value: string|null, hint: string|null}>} */
    private static function set(PaymentMethod $method, PaymentProvider $row, bool $sandbox): array
    {
        $saved = GatewayCredentials::forMode($row, $sandbox);

        return [
            'complete' => GatewayCredentials::complete($method, $saved),
            'fields' => array_map(static fn (array $field): array => [
                'key' => $field['key'],
                'secret' => $field['secret'],
                'set' => isset($saved[$field['key']]),
                'value' => $field['secret'] ? null : ($saved[$field['key']] ?? null),
                'hint' => $field['secret'] && $field['hint'] ? GatewayCredentials::hint($saved[$field['key']] ?? null) : null,
            ], GatewayCredentials::fields($method)),
        ];
    }
}

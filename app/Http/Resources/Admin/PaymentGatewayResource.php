<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Support\GatewayCredentials;
use App\Domain\Payment\Support\GatewayEnvFallback;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One online gateway on Integrations › Payments (owner request 2026-11-19). A secret key is
 * NEVER returned, not even masked — only whether it is set (rule 20). Non-secret keys (a store
 * or merchant id) are returned so the owner can see which account is connected.
 *
 * `status` is what checkout sees right now ({@see \App\Domain\Payment\Services\OnlineGateways}):
 * in production a live gateway that is not `verified` saves fine but takes no payment
 * (`reason: unverified`) until the owner verifies it from the CLI.
 *
 * @property array{gateway: PaymentProvider, method: PaymentMethod, status: array{enabled: bool, reason: string|null}} $resource
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

        return [
            /** @var 'sslcommerz'|'paystation' */
            'code' => $row->code,
            'name' => $row->name,
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
            /** @var array{sandbox: array{complete: bool, fields: list<array{key: string, secret: bool, set: bool, value: string|null}>}, live: array{complete: bool, fields: list<array{key: string, secret: bool, set: bool, value: string|null}>}} Saved keys per mode. `value` is null for every secret. `complete` counts saved keys only. */
            'credentials' => [
                GatewayCredentials::SANDBOX => self::set($method, $row, true),
                GatewayCredentials::LIVE => self::set($method, $row, false),
            ],
        ];
    }

    /** @return array{complete: bool, fields: list<array{key: string, secret: bool, set: bool, value: string|null}>} */
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
            ], GatewayCredentials::fields($method)),
        ];
    }
}

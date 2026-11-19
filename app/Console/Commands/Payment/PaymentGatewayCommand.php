<?php

declare(strict_types=1);

namespace App\Console\Commands\Payment;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Services\OnlineGateways;
use App\Domain\Payment\Support\GatewayCredentials;
use Illuminate\Console\Command;

/**
 * Show or switch an online gateway's row (P4-PAY-02), for any gateway code (sslcommerz,
 * paystation, …). The sandbox/live switch is also on Integrations › Payments in the admin;
 * `--verify` stays here, an owner decision taken only after a live merchant account has been
 * tested end to end — until then production offers no online payment through it (rule 33a
 * spirit). `--live` is refused while the gateway's live key set is incomplete
 * ({@see GatewayCredentials::complete}); env never supplies a live key. Never prints a credential.
 */
final class PaymentGatewayCommand extends Command
{
    protected $signature = 'toymart:payments:gateway {code=sslcommerz}
        {--activate} {--deactivate} {--verify} {--unverify} {--live : Leave sandbox mode} {--sandbox : Return to sandbox mode}';

    protected $description = 'Show or change an online payment gateway\'s active / verified / sandbox switches';

    public function handle(OnlineGateways $gateways): int
    {
        $method = PaymentMethod::tryFrom((string) $this->argument('code'));
        $row = $method === null ? null : PaymentProvider::forCode($method->value);
        if ($method === null || $row === null) {
            $this->error('Unknown gateway.');

            return self::FAILURE;
        }

        if ($this->option('live') && $this->option('sandbox')) {
            $this->error('Choose one of --live or --sandbox.');

            return self::FAILURE;
        }
        if ($this->option('live') && ! GatewayCredentials::complete($method, GatewayCredentials::forMode($row, sandbox: false))) {
            $this->error("Refusing --live: {$method->value} has no complete live key set. Save the live keys at Admin → Integrations → Payments first. Nothing was changed.");

            return self::FAILURE;
        }

        if ($this->option('activate')) {
            $row->is_active = true;
        }
        if ($this->option('deactivate')) {
            $row->is_active = false;
        }
        if ($this->option('verify')) {
            $row->verified = true;
            $row->verified_at = now();
        }
        if ($this->option('unverify')) {
            $row->verified = false;
            $row->verified_at = null;
        }
        if ($this->option('live')) {
            $row->sandbox = false;
        }
        if ($this->option('sandbox')) {
            $row->sandbox = true;
        }
        $row->save();

        $status = $gateways->status($method);
        $this->table(['method', 'mode', 'active', 'verified', 'configured', 'enabled', 'reason'], [[
            $status['method'], GatewayCredentials::mode($status['sandbox']), self::yes($status['active']), self::yes($status['verified']),
            self::yes($status['configured']), self::yes($status['enabled']), $status['reason'] ?? '—',
        ]]);

        return self::SUCCESS;
    }

    private static function yes(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands\Payment;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Services\OnlineGateways;
use Illuminate\Console\Command;

/**
 * Show or switch an online gateway's row (P4-PAY-02). `--verify` is an owner decision taken
 * only after a live merchant account has been tested end to end; until then production
 * offers no online payment through it (rule 33a spirit). Never prints a credential.
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
        $this->table(['method', 'active', 'verified', 'sandbox', 'configured', 'enabled', 'reason'], [[
            $status['method'], self::yes($status['active']), self::yes($status['verified']), self::yes($status['sandbox']),
            self::yes($status['configured']), self::yes($status['enabled']), $status['reason'] ?? '—',
        ]]);

        return self::SUCCESS;
    }

    private static function yes(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}

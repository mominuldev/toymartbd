<?php

declare(strict_types=1);

use App\Support\ProductionSafety;
use Illuminate\Config\Repository;
use Psr\Log\AbstractLogger;

function spyLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $critical = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            if ($level === 'critical') {
                $this->critical[] = (string) $message;
            }
        }
    };
}

it('forces APP_DEBUG off in production and logs it', function (): void {
    $config = new Repository(['app' => ['debug' => true], 'auth' => ['two_factor' => ['enforce' => true]]]);
    $log = spyLogger();

    ProductionSafety::enforce(true, $config, $log);

    expect($config->get('app.debug'))->toBeFalse()
        ->and($log->critical)->toHaveCount(1);
});

it('leaves debug alone outside production, and is silent when it is already off', function (): void {
    $local = new Repository(['app' => ['debug' => true], 'auth' => ['two_factor' => ['enforce' => true]]]);
    ProductionSafety::enforce(false, $local, $log = spyLogger());
    expect($local->get('app.debug'))->toBeTrue()->and($log->critical)->toBe([]);

    $off = new Repository(['app' => ['debug' => false], 'auth' => ['two_factor' => ['enforce' => true]]]);
    ProductionSafety::enforce(true, $off, $log = spyLogger());
    expect($off->get('app.debug'))->toBeFalse()->and($log->critical)->toBe([]);
});

it('forces staff two-factor on in production, whatever the env says, and logs it', function (): void {
    $config = new Repository(['app' => ['debug' => false], 'auth' => ['two_factor' => ['enforce' => false]]]);
    ProductionSafety::enforce(true, $config, $log = spyLogger());

    expect($config->get('auth.two_factor.enforce'))->toBeTrue()
        ->and($config->get('auth.two_factor.enforce_forced_on'))->toBeTrue()
        ->and($log->critical)->toHaveCount(1);

    $local = new Repository(['auth' => ['two_factor' => ['enforce' => false]]]);
    ProductionSafety::enforce(false, $local, spyLogger());
    expect($local->get('auth.two_factor.enforce'))->toBeFalse();
});

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
    $config = new Repository(['app' => ['debug' => true]]);
    $log = spyLogger();

    ProductionSafety::enforce(true, $config, $log);

    expect($config->get('app.debug'))->toBeFalse()
        ->and($log->critical)->toHaveCount(1);
});

it('leaves debug alone outside production, and is silent when it is already off', function (): void {
    $local = new Repository(['app' => ['debug' => true]]);
    ProductionSafety::enforce(false, $local, $log = spyLogger());
    expect($local->get('app.debug'))->toBeTrue()->and($log->critical)->toBe([]);

    $off = new Repository(['app' => ['debug' => false]]);
    ProductionSafety::enforce(true, $off, $log = spyLogger());
    expect($off->get('app.debug'))->toBeFalse()->and($log->critical)->toBe([]);
});

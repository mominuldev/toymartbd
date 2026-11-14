<?php

declare(strict_types=1);

use App\Support\Logging\RedactLogs;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->logFile = storage_path('framework/testing/observability-'.uniqid().'.log');
    File::ensureDirectoryExists(dirname($this->logFile));
});

afterEach(function (): void {
    File::delete($this->logFile);
});

function observabilityLog(string $path): string
{
    return File::exists($path) ? File::get($path) : '';
}

it('applies the redaction tap to every log channel', function (): void {
    /** @var array<string, array<string, mixed>> $channels */
    $channels = config('logging.channels');

    foreach ($channels as $name => $channel) {
        // `stack` reuses its children's (tapped) handlers; `emergency` is Laravel's last-resort fallback.
        if (in_array($name, ['stack', 'emergency'], true)) {
            continue;
        }

        expect(in_array(RedactLogs::class, $channel['tap'] ?? [], true))->toBeTrue("Channel [{$name}] is missing the redaction tap");
    }
});

it('writes one JSON object per line with the request id and nothing sensitive', function (): void {
    config(['logging.channels.json.handler_with.stream' => $this->logFile]);
    Log::forgetChannel('json');
    Context::add('request_id', 'req-observability-1');

    Log::channel('json')->warning('Courier booking failed for 01712345678', [
        'password' => 'hunter2-secret',
        'customer' => ['phone' => '+8801812345678', 'address_line' => 'House 12, Road 5, Dhanmondi'],
        'headers' => ['Authorization' => 'Bearer tmbd_abcdef'],
        'order' => 'TMB-0042',
    ]);

    $line = trim(observabilityLog($this->logFile));
    $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

    expect($entry['message'])->toBe('Courier booking failed for 017****5678')
        ->and($entry['level_name'])->toBe('WARNING')
        ->and($entry['extra']['request_id'])->toBe('req-observability-1')
        ->and($entry['context']['order'])->toBe('TMB-0042')
        ->and($entry['context']['customer']['phone'])->toBe('018****5678')
        ->and($line)->not->toContain('01712345678')
        ->not->toContain('1812345678')
        ->not->toContain('hunter2')
        ->not->toContain('House 12')
        ->not->toContain('tmbd_abcdef');
});

it('masks a phone inside an exception message, where the processor cannot reach', function (): void {
    config(['logging.channels.json.handler_with.stream' => $this->logFile]);
    Log::forgetChannel('json');

    // A QueryException carries its bindings in the message — the realistic leak.
    Log::channel('json')->error('Insert failed', ['exception' => new RuntimeException("insert into customers (phone) values ('+8801712345678')")]);

    $output = observabilityLog($this->logFile);

    expect($output)->toContain('017****5678')->not->toContain('1712345678');
});

it('redacts the default stack channel too', function (): void {
    config([
        'logging.channels.single.path' => $this->logFile,
        'logging.channels.stack.channels' => ['single'],
    ]);
    Log::forgetChannel('single');
    Log::forgetChannel('stack');

    Log::channel('stack')->info('Login attempt 01912345678', ['password' => 'hunter2-secret']);

    $output = observabilityLog($this->logFile);

    expect($output)->toContain('019****5678')->toContain('[redacted]')
        ->not->toContain('1912345678')
        ->not->toContain('hunter2');
});

it('stamps every JSON line with app, environment and release for aggregation (P10)', function (): void {
    config(['logging.channels.json.handler_with.stream' => $this->logFile, 'sentry.release' => 'abc1234', 'app.env' => 'production']);
    Log::forgetChannel('json');
    Context::add('request_id', 'req-service-1');

    Log::channel('json')->info('Order confirmed for ops@example.com', ['otp' => '483920']);

    $entry = json_decode(trim(observabilityLog($this->logFile)), true, flags: JSON_THROW_ON_ERROR);
    expect($entry['extra']['service'])->toBe(['app' => 'toymartbd-api', 'env' => 'production', 'release' => 'abc1234'])
        ->and($entry['extra']['request_id'])->toBe('req-service-1')
        ->and($entry['message'])->toBe('Order confirmed for o***@example.com')
        ->and($entry['context']['otp'])->toBe('[redacted]');
});

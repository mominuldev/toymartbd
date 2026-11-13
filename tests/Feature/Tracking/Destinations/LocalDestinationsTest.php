<?php

declare(strict_types=1);

use App\Domain\Tracking\Destinations\LogTrackingDestination;
use App\Domain\Tracking\Destinations\NullTrackingDestination;
use App\Domain\Tracking\Enums\DispatchStatus;
use Illuminate\Support\Facades\Http;
use Psr\Log\AbstractLogger;
use Tests\Support\TrackingFixtures as TF;

beforeEach(fn () => Http::preventStrayRequests());

it('null destination accepts without any network call and stores no PII', function (): void {
    $result = (new NullTrackingDestination)->send(TF::event());

    expect($result->status)->toBe(DispatchStatus::Sent)
        ->and($result->sentPayload['event_name'])->toBe('purchase')
        ->and(json_encode($result->sentPayload))->not->toContain('1712345678')->not->toContain('Example.COM')
        ->not->toContain('Rahim')->not->toContain('103.4.145.2')->not->toContain('fb.1.');
    Http::assertNothingSent();
});

it('log destination logs a PII-free line', function (): void {
    $logger = new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $lines = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->lines[] = $message.' '.json_encode($context);
        }
    };

    $result = (new LogTrackingDestination($logger))->validate(TF::event());

    expect($result->status)->toBe(DispatchStatus::Sent)
        ->and($logger->lines)->toHaveCount(1)
        ->and($logger->lines[0])->toContain('TMB-261113-0001')->toContain('"test_mode":true')
        ->not->toContain('1712345678')->not->toContain('Example.COM')->not->toContain('Rahim')
        ->not->toContain('103.4.145.2')->not->toContain('Mozilla');
});

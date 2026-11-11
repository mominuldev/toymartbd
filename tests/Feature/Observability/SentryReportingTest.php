<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sentry\Event;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Serializer\PayloadSerializer;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

final class CapturingSentryTransport implements TransportInterface
{
    /** @var list<Event> */
    public array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }
}

beforeEach(function (): void {
    $this->transport = new CapturingSentryTransport;

    // A syntactically valid DSN that is never contacted: the transport captures instead.
    config([
        'sentry.dsn' => 'https://public@o0.ingest.sentry.io/1',
        'sentry.transport' => $this->transport,
    ]);
    app()->forgetInstance(HubInterface::class);
    SentrySdk::setCurrentHub(app(HubInterface::class));

    Route::middleware('api')->post('api/v1/_test/boom', function (): void {
        throw new RuntimeException('Booking failed for customer +88017'.'12345678');
    });
});

afterEach(function (): void {
    // PHPUnit keeps every TestCase alive to the end of the run: drop the captured events
    // (whole stack traces) so they do not pile up in memory.
    $this->transport->events = [];
    config(['sentry.dsn' => null, 'sentry.transport' => null]);
    app()->forgetInstance(HubInterface::class);
    SentrySdk::setCurrentHub(app(HubInterface::class));
});

it('reports an unhandled exception to Sentry tagged with the request id and without PII', function (): void {
    // Built from pieces so the literals never appear in the stack frames' source context —
    // otherwise the assertion would only prove the test file was masked.
    $this->postJson('api/v1/_test/boom', [
        'phone' => '0181'.'2345678',
        'password' => 'hunter'.'2-secret',
        'address_line' => 'House'.' 12, Road 5, Dhanmondi',
    ], ['X-Request-Id' => 'sentry-trace-0001', 'Authorization' => 'Bearer tmbd_'.'abcdef'])
        ->assertStatus(500)
        ->assertJsonPath('request_id', 'sentry-trace-0001');

    expect($this->transport->events)->toHaveCount(1);

    $event = $this->transport->events[0];
    $payload = (new PayloadSerializer(new Options(['dsn' => 'https://public@o0.ingest.sentry.io/1'])))->serialize($event);

    expect($event->getTags())->toHaveKey('request_id', 'sentry-trace-0001')
        ->and($event->getExceptions()[0]->getValue())->toBe('Booking failed for customer 017****5678')
        ->and($payload)->toContain('sentry-trace-0001')
        ->not->toContain('1712345678')
        ->not->toContain('1812345678')
        ->not->toContain('hunter2')
        ->not->toContain('House 12')
        ->not->toContain('tmbd_abcdef');
});

it('is disabled by default: no DSN, and PII is off whatever the environment says', function (): void {
    // .env.example leaves SENTRY_LARAVEL_DSN empty; the real HttpTransport sends nothing without a DSN.
    config(['sentry.dsn' => null, 'sentry.transport' => null]);
    app()->forgetInstance(HubInterface::class);
    $options = app(HubInterface::class)->getClient()?->getOptions();

    expect($options?->getDsn())->toBeNull()
        ->and($options?->shouldSendDefaultPii())->toBeFalse();
});

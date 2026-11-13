<?php

declare(strict_types=1);

use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\Drivers\ReveSmsGateway;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Services\MessagingSettings;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * The REVE driver against faked HTTP. Response bodies below are the two shapes observed
 * from the live endpoint on 2026-11-13 (dummy credentials) plus an accepted shape whose
 * Status value is NOT yet confirmed — the driver keys acceptance on Message_ID, so the
 * exact success code does not matter.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'notifications.sms.driver' => 'reve',
        'notifications.sms.drivers.reve' => [
            'base_url' => 'https://smpp.example.test:7790',
            'api_key' => 'test-key',
            'secret_key' => 'test-secret',
            'sender_id' => 'ToyMartBD',
            'timeout' => 5,
        ],
    ]);
});

function reveGateway(): ReveSmsGateway
{
    return app(ReveSmsGateway::class);
}

it('posts JSON with credentials in the body, never the URL, and the number without a plus', function (): void {
    Http::fake(['smpp.example.test:7790/sendtext' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD', 'Message_ID' => '9123'])]);

    $result = reveGateway()->send('+8801712345678', 'Toy Mart BD: hello', 'test');

    expect($result->accepted)->toBeTrue()
        ->and($result->providerMessageId)->toBe('9123')
        ->and($result->segments)->toBe(1);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://smpp.example.test:7790/sendtext'
            && ! str_contains($request->url(), 'test-key')
            && $request['apikey'] === 'test-key'
            && $request['secretkey'] === 'test-secret'
            && $request['callerID'] === 'ToyMartBD'
            && $request['toUser'] === '8801712345678'
            && $request['messageContent'] === 'Toy Mart BD: hello';
    });
});

it('counts a Bangla message as UCS-2 segments and sends the text untouched', function (): void {
    Http::fake(['*' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD', 'Message_ID' => '77'])]);
    $bangla = str_repeat('টয় মার্ট ', 10); // 100 chars → 2 UCS-2 segments

    $result = reveGateway()->send('+8801812345678', $bangla, 'test');

    expect($result->accepted)->toBeTrue()->and($result->segments)->toBe(2);
    Http::assertSent(fn (Request $r): bool => $r['messageContent'] === $bangla);
});

it('treats the provider’s error bodies (HTTP 200, empty Message_ID) as refusals', function (array $body, string $expected): void {
    Http::fake(['*' => Http::response($body)]);

    $result = reveGateway()->send('+8801712345678', 'hi', 'test');

    expect($result->accepted)->toBeFalse()
        ->and($result->providerMessageId)->toBeNull()
        ->and($result->error)->toContain($expected);
})->with([
    'bad credentials' => [['Status' => '109', 'Text' => 'Invalid api key/secret key', 'Message_ID' => ''], '109'],
    'bad parameters' => [['Status' => '114', 'Text' => 'Inappropriate request parameter', 'Message_ID' => ''], '114'],
    'rejected' => [['Status' => '109', 'Text' => 'REJECTD', 'Message_ID' => '', 'Delivery Time' => '0'], 'REJECTD'],
]);

it('refuses a malformed or server-error response without throwing', function (): void {
    Http::fake(['*' => Http::response('<html>Bad gateway</html>', 502)]);

    $result = reveGateway()->send('+8801712345678', 'hi', 'test');

    expect($result->accepted)->toBeFalse()->and($result->error)->toContain('HTTP 502');
});

it('refuses on a connection failure', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(reveGateway()->send('+8801712345678', 'hi', 'test')->accepted)->toBeFalse();
});

it('prefers credentials saved at Admin → Integrations → Messaging over env, per key', function (): void {
    Http::fake(['*' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD', 'Message_ID' => '8'])]);
    Setting::put(MessagingSettings::REVE_SECRET_KEY, 'saved-secret', SettingGroup::Notification, true);
    Setting::put(MessagingSettings::REVE_SENDER_ID, 'SavedID', SettingGroup::Notification);

    reveGateway()->send('+8801712345678', 'hi', 'test');

    // Saved values win; the API key and base URL nobody saved still come from env.
    Http::assertSent(fn (Request $r): bool => $r['secretkey'] === 'saved-secret'
        && $r['callerID'] === 'SavedID'
        && $r['apikey'] === 'test-key'
        && str_starts_with($r->url(), 'https://smpp.example.test:7790'));
});

it('refuses without calling the provider when a credential is missing', function (): void {
    config(['notifications.sms.drivers.reve.secret_key' => null]);
    Http::fake();

    $result = reveGateway()->send('+8801712345678', 'hi', 'test');

    expect($result->accepted)->toBeFalse()->and($result->error)->toContain('secret_key');
    Http::assertNothingSent();
});

it('never logs the body, the secret or the full phone number', function (): void {
    Http::fake(['*' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD', 'Message_ID' => '1'])]);
    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = json_encode([$message->message, $message->context]);
    });

    reveGateway()->send('+8801712345678', 'Your code is 482913', 'otp.login');

    $all = implode("\n", $logged);
    expect($all)->not->toContain('482913')
        ->and($all)->not->toContain('test-secret')
        ->and($all)->not->toContain('test-key')
        ->and($all)->not->toContain('01712345678');
});

it('is the container’s SMS driver when configured, and records the send with its cost', function (): void {
    Http::fake(['*' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD', 'Message_ID' => '555'])]);

    $result = app(SmsGateway::class)->send('+8801712345678', 'hello', 'test');

    expect($result->accepted)->toBeTrue();
    $log = NotificationLog::query()->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->provider_message_id ?? null)->toBe('555');
});

it('sends one test SMS from the console command', function (): void {
    Http::fake(['*' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD', 'Message_ID' => '42'])]);

    $this->artisan('toymart:sms:test', ['phone' => '01712345678', '--bn' => true])
        ->expectsOutputToContain('message id 42')
        ->assertSuccessful();

    $this->artisan('toymart:sms:test', ['phone' => '01112345678'])->assertExitCode(2);
});

<?php

declare(strict_types=1);

/*
 * Sentry wiring (P10, SYSTEM-ARCHITECTURE §11): DSN-less = off everywhere; the admin gets its DSN,
 * release and environment from the server and its CSP admits only that ingest origin.
 */

it('renders no Sentry config and no ingest origin in the admin shell without a DSN', function (): void {
    $this->withoutVite();
    config(['monitoring.sentry_admin.dsn' => null]);

    $response = $this->get('/admin')->assertOk();

    expect($response->getContent())->not->toContain('tm-sentry')
        ->and((string) $response->headers->get('Content-Security-Policy'))->not->toContain('sentry');
});

it('hands the admin its DSN, the git-SHA release and environment, and allows only that origin', function (): void {
    $this->withoutVite();
    config([
        'monitoring.sentry_admin.dsn' => 'https://publickey@o42.ingest.sentry.example/7',
        'sentry.release' => '4b9d279abc',
        'sentry.environment' => 'staging',
    ]);

    $response = $this->get('/admin')->assertOk();
    preg_match('/<meta name="tm-sentry" content="([^"]+)">/', (string) $response->getContent(), $m);
    $meta = json_decode(html_entity_decode($m[1] ?? ''), true);

    expect($meta)->toBe([
        'dsn' => 'https://publickey@o42.ingest.sentry.example/7',
        'release' => '4b9d279abc',
        'environment' => 'staging',
        'sampleRate' => '1.0',
        'tracesSampleRate' => '0',
    ])->and((string) $response->headers->get('Content-Security-Policy'))
        ->toContain('connect-src \'self\' https://o42.ingest.sentry.example')
        ->not->toContain('publickey');
});

it('keeps the API SDK off without a DSN and never sends default PII', function (): void {
    expect(config('sentry.dsn'))->toBeNull()
        ->and(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.before_send'))->toBe([App\Support\Logging\SentryEventScrubber::class, 'beforeSend']);
});

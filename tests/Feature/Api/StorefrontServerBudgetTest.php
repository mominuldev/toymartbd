<?php

declare(strict_types=1);

use App\Providers\ApiServiceProvider;

/*
 * Phase 9 SEO audit (docs/audits/SEO-AND-COVERAGE.md): crawling the storefront turned listing
 * pages into 500s. The Next.js server's cached (ISR) reads deliberately carry no shopper IP
 * (lib/api.ts), so they all landed in ONE 120/min "ip:<next server>" bucket: a crawler walking
 * distinct listing URLs — or a cache purge — exhausted it and every cache miss on the site got a
 * 429, which the storefront renders as a 500. Those reads now get a whole-shop server budget,
 * while anything carrying a shopper IP (and anything not from a trusted proxy) keeps 120/min.
 */

const NEXT_SERVER = '10.0.0.5';

it('gives the Next server\'s own cached reads (trusted proxy, no X-Forwarded-For) the server budget', function (): void {
    config(['trustedproxy.proxies' => [NEXT_SERVER]]);

    for ($i = 1; $i <= ApiServiceProvider::STOREFRONT_PER_MINUTE + 30; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => NEXT_SERVER])->getJson('/api/v1/storefront/ping')->assertOk();
    }

    // A shopper behind the same server still has their own, untouched 120/min bucket.
    $this->withServerVariables(['REMOTE_ADDR' => NEXT_SERVER])->getJson('/api/v1/storefront/ping', ['X-Forwarded-For' => '203.0.113.9'])->assertOk();
    expect(ApiServiceProvider::STOREFRONT_SERVER_PER_MINUTE)->toBeGreaterThan(10 * ApiServiceProvider::STOREFRONT_PER_MINUTE);
});

it('still limits a forwarded shopper IP to 120/min', function (): void {
    config(['trustedproxy.proxies' => [NEXT_SERVER]]);

    for ($i = 1; $i <= ApiServiceProvider::STOREFRONT_PER_MINUTE; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => NEXT_SERVER])->getJson('/api/v1/storefront/ping', ['X-Forwarded-For' => '203.0.113.9'])->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => NEXT_SERVER])->getJson('/api/v1/storefront/ping', ['X-Forwarded-For' => '203.0.113.9'])->assertStatus(429);
    // The server's cached reads are a different bucket and keep working.
    $this->withServerVariables(['REMOTE_ADDR' => NEXT_SERVER])->getJson('/api/v1/storefront/ping')->assertOk();
});

it('never gives the server budget to a client that is not a trusted proxy', function (): void {
    config(['trustedproxy.proxies' => [NEXT_SERVER]]);

    for ($i = 1; $i <= ApiServiceProvider::STOREFRONT_PER_MINUTE; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->getJson('/api/v1/storefront/ping')->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->getJson('/api/v1/storefront/ping')->assertStatus(429);
});

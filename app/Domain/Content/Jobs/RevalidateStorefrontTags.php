<?php

declare(strict_types=1);

namespace App\Domain\Content\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Tells the Next.js storefront to drop cached pages by tag — the sender side of
 * docs/api/revalidation.md.
 *
 * - Queued and released only after the surrounding transaction commits (rule 4).
 * - Signs `"<timestamp>.<raw body>"` with HMAC-SHA256 under STOREFRONT_REVALIDATE_SECRET.
 * - Network errors and 5xx are retried with backoff; a 4xx is a bug on one side of
 *   the contract and is logged, not retried.
 * - No-op (debug log) when the storefront URL or secret is not configured.
 */
final class RevalidateStorefrontTags implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string PATH = '/api/revalidate';

    /** Receiver rule 3: `^[a-z0-9:_-]{1,100}$`, 1–50 tags. */
    public const string TAG_PATTERN = '/^[a-z0-9:_-]{1,100}$/';

    public const int MAX_TAGS = 50;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<string> */
    public readonly array $tags;

    /**
     * @param  list<string>  $tags
     */
    public function __construct(array $tags)
    {
        $this->tags = array_values(array_unique($tags));
        $this->afterCommit();
        $this->onQueue('index');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $url = config('services.storefront.url');
        $secret = config('services.storefront.revalidate_secret');

        if (! is_string($url) || $url === '' || ! is_string($secret) || $secret === '') {
            Log::debug('Storefront revalidation skipped: STOREFRONT_URL or STOREFRONT_REVALIDATE_SECRET is empty.', ['tags' => $this->tags]);

            return;
        }

        $tags = array_values(array_filter($this->tags, static fn (string $tag): bool => preg_match(self::TAG_PATTERN, $tag) === 1));

        if (count($tags) !== count($this->tags)) {
            Log::warning('Storefront revalidation dropped tags outside the contract vocabulary.', [
                'dropped' => array_values(array_diff($this->tags, $tags)),
            ]);
        }

        foreach (array_chunk($tags, self::MAX_TAGS) as $chunk) {
            $this->send(rtrim($url, '/').self::PATH, $secret, $chunk);
        }
    }

    /**
     * @param  list<string>  $tags
     */
    private function send(string $endpoint, string $secret, array $tags): void
    {
        $body = json_encode(['tags' => $tags], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Revalidate-Timestamp' => $timestamp,
                    'X-Revalidate-Signature' => $signature,
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Storefront revalidation could not connect; will retry.', previous: $e);
        }

        if ($response->serverError()) {
            throw new RuntimeException("Storefront revalidation failed with HTTP {$response->status()}; will retry.");
        }

        if ($response->clientError()) {
            // A 4xx means the two sides disagree on the contract (signature, clock, tag shape).
            // Retrying cannot fix that, so log loudly and stop.
            Log::error('Storefront revalidation rejected; not retrying.', ['status' => $response->status(), 'tags' => $tags]);
            $this->fail(new RuntimeException("Storefront revalidation rejected with HTTP {$response->status()}."));
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Load;

use CurlHandle;
use RuntimeException;

/**
 * A tiny concurrent HTTP client for the load harness (curl_multi — no extension or binary
 * beyond what PHP already has, so the same harness runs locally and in CI's PHP job).
 *
 * `burst()` opens every request at once (up to `$concurrency` in flight) and returns one
 * result per request, in request order, with its wall-clock latency.
 */
final class LoadHttp
{
    public function __construct(private readonly string $base) {}

    /**
     * @param  list<array{method: string, path: string, headers?: array<string, string>, json?: mixed, jar?: string}>  $requests
     * @return list<array{status: int, body: string, json: mixed, ms: float, headers: array<string, string>, error: ?string}>
     */
    public function burst(array $requests, int $concurrency = 1000): array
    {
        $multi = curl_multi_init();
        $results = [];
        $queue = array_keys($requests);
        /** @var array<int, array{handle: CurlHandle, index: int, started: float, headers: array<string, string>}> $active */
        $active = [];

        $start = function (int $index) use ($requests, $multi, &$active): void {
            $request = $requests[$index];
            $headers = [];
            $handle = $this->handle($request, $headers);
            curl_multi_add_handle($multi, $handle);
            $active[spl_object_id($handle)] = ['handle' => $handle, 'index' => $index, 'started' => microtime(true), 'headers' => &$headers];
            unset($headers);
        };

        while ($queue !== [] && count($active) < $concurrency) {
            $start((int) array_shift($queue));
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($status !== CURLM_OK) {
                throw new RuntimeException('curl_multi_exec failed: '.curl_multi_strerror($status));
            }
            while (($info = curl_multi_info_read($multi)) !== false) {
                /** @var CurlHandle $handle */
                $handle = $info['handle'];
                $slot = $active[spl_object_id($handle)];
                $body = (string) curl_multi_getcontent($handle);
                $results[$slot['index']] = [
                    'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    'body' => $body,
                    'json' => json_decode($body, true),
                    'ms' => round((microtime(true) - $slot['started']) * 1000, 1),
                    'headers' => $slot['headers'],
                    'error' => $info['result'] === CURLE_OK ? null : curl_strerror($info['result']),
                ];
                curl_multi_remove_handle($multi, $handle);
                unset($active[spl_object_id($handle)]);
                if ($queue !== []) {
                    $start((int) array_shift($queue));
                }
            }
            if ($running > 0) {
                curl_multi_select($multi, 0.05);
            }
        } while ($running > 0 || $active !== []);

        curl_multi_close($multi);
        ksort($results);

        return array_values($results);
    }

    /**
     * @param  array{method: string, path: string, headers?: array<string, string>, json?: mixed, jar?: string}  $request
     * @param  array<string, string>  $headers  filled with the response headers
     */
    private function handle(array $request, array &$headers): CurlHandle
    {
        $handle = curl_init($this->base.$request['path']);
        if ($handle === false) {
            throw new RuntimeException('curl_init failed');
        }
        $out = ['Accept: application/json'];
        foreach ($request['headers'] ?? [] as $name => $value) {
            $out[] = $name.': '.$value;
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $request['method'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $h, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];
        if (array_key_exists('json', $request)) {
            $out[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($request['json'], JSON_THROW_ON_ERROR);
        }
        if (isset($request['jar'])) {
            $options[CURLOPT_COOKIEFILE] = $request['jar'];
            $options[CURLOPT_COOKIEJAR] = $request['jar'];
        }
        $options[CURLOPT_HTTPHEADER] = $out;
        curl_setopt_array($handle, $options);

        return $handle;
    }

    /** @param  list<float>  $values */
    public static function percentile(array $values, float $p): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $rank = (int) ceil($p / 100 * count($values)) - 1;

        return $values[max(0, min(count($values) - 1, $rank))];
    }
}

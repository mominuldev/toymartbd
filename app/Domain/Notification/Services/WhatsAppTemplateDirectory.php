<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Contracts\WhatsAppCloudAccount;
use App\Domain\Notification\Exceptions\WhatsAppTemplateSyncFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reads the WhatsApp Business Account's template list from Meta:
 * `GET /{version}/{waba-id}/message_templates?fields=name,language,status,category&limit=100`
 * (docs/graph-api/reference/whats-app-business-account/message_templates), Bearer token from
 * the Inbox's WhatsApp number ({@see WhatsAppCloudAccount}).
 *
 * Pagination (ASSUMED — the reference shows `paging.cursors` and points to the Graph paging
 * guide): follow `paging.next` when Meta gives one, but only to the configured Graph host, so a
 * forged or odd URL never receives the token; otherwise ask again with `after` = the `after`
 * cursor while a full page came back. At most {@see self::MAX_PAGES} pages. Read-only, never
 * inside a transaction (rule 4).
 */
final readonly class WhatsAppTemplateDirectory
{
    public const int PAGE_SIZE = 100;

    public const int MAX_PAGES = 20;

    public function __construct(private WhatsAppCloudAccount $account) {}

    /**
     * @return list<array{name: string, language: string, status: string, category: ?string}>
     *
     * @throws WhatsAppTemplateSyncFailed
     */
    public function all(): array
    {
        $credentials = $this->account->credentials();
        if (! $credentials->canListTemplates()) {
            throw WhatsAppTemplateSyncFailed::notConfigured();
        }

        $first = $credentials->url(rawurlencode((string) $credentials->businessAccountId).'/message_templates');
        $base = rtrim($credentials->graphBaseUrl, '/').'/';
        $url = $first;
        $query = ['fields' => 'name,language,status,category', 'limit' => self::PAGE_SIZE];
        $templates = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->get((string) $credentials->accessToken, $credentials->timeout, $url, $query);

            $data = $response->json('data');
            $rows = is_array($data) ? $data : [];
            foreach ($rows as $row) {
                if (! is_array($row) || ! is_string($row['name'] ?? null) || ! is_string($row['language'] ?? null) || ! is_string($row['status'] ?? null)) {
                    continue;
                }
                $templates[] = [
                    'name' => $row['name'],
                    'language' => $row['language'],
                    'status' => $row['status'],
                    'category' => is_string($row['category'] ?? null) ? $row['category'] : null,
                ];
            }

            $next = $response->json('paging.next');
            $after = $response->json('paging.cursors.after');
            if (is_string($next) && str_starts_with($next, $base)) {
                // The next URL carries its own query (fields, limit, after).
                $url = $next;
                $query = [];
            } elseif (is_string($after) && $after !== '' && count($rows) >= self::PAGE_SIZE) {
                $url = $first;
                $query = ['fields' => 'name,language,status,category', 'limit' => self::PAGE_SIZE, 'after' => $after];
            } else {
                break;
            }
        }

        return $templates;
    }

    /** @param  array<string, string|int>  $query */
    private function get(string $token, int $timeout, string $url, array $query): Response
    {
        try {
            $response = Http::withToken($token)->acceptJson()->timeout($timeout)->get($url, $query);
        } catch (ConnectionException) {
            throw WhatsAppTemplateSyncFailed::provider('unreachable');
        }

        if (! $response->successful()) {
            $code = $response->json('error.code');

            throw WhatsAppTemplateSyncFailed::provider(is_int($code) || is_string($code) ? 'meta_'.$code : 'http_'.$response->status());
        }

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Tracking\Models\TrackingEvent;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One tracking event with its payload AS SENT (user data already SHA-256 hashed, IP and user
 * agent redacted by the dispatcher) and each destination's stored response. As a last line
 * of defence (rule 20) any `access_token` / `api_secret` key is removed before it leaves.
 *
 * @property TrackingEvent $resource
 */
#[SchemaName('TrackingEventDetail')]
final class TrackingEventDetailResource extends ApiResource
{
    /** Keys that must never appear in an admin response, at any depth. */
    private const array SECRET_KEYS = ['access_token', 'api_secret', 'accesstoken', 'apisecret'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $event = $this->resource;
        $base = (new TrackingEventResource($event))->toArray($request);
        $consent = $event->consent_state ?? [];

        return [
            ...$base,
            /** @var string|null */
            'session_id' => $event->session_id,
            /** @var string|null The consent policy version the visitor answered. */
            'consent_policy_version' => $consent['policy_version'] ?? null,
            /** @var array<string, mixed> The event as recorded and, under `sent`, as sent to each destination. */
            'payload' => self::scrub($event->payload ?? []),
            /** @var array<string, mixed>|null What GA4 answered (status, excerpt, validation messages). */
            'ga4_response' => is_array($event->ga4_response) ? self::scrub($event->ga4_response) : null,
            /** @var array<string, mixed>|null What Meta answered (status, excerpt, error). */
            'meta_response' => is_array($event->meta_response) ? self::scrub($event->meta_response) : null,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function scrub(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower(str_replace(['-', '_'], '', $key)), self::SECRET_KEYS, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return $clean;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Tracking;

use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\TrackingClientContext;
use Illuminate\Validation\Rule;

/**
 * The `client` + `consent` shape shared by `POST /storefront/tracking/events` and the
 * optional `tracking` object on `POST /storefront/checkout` (docs/api/tracking.md §2).
 * The Next.js server fills `client` from the shopper's request (real IP, user agent, the
 * `_ga`, `_fbp`, `_fbc` cookies) — the API request's own IP is the storefront server's.
 */
final class TrackingContextRules
{
    /**
     * @param  string  $prefix  '' for the events body, 'tracking.' inside checkout
     * @param  bool  $required  client + consent required (events) or only with the parent (checkout)
     * @return array<string, mixed>
     */
    public static function rules(string $prefix, bool $required): array
    {
        $presence = $required ? 'required' : 'required_with:'.rtrim($prefix, '.');

        return [
            $prefix.'client' => [$presence, 'array:ip,user_agent,ga_client_id,ga_session_id,fbp,fbc'],
            $prefix.'client.ip' => ['nullable', 'string', 'ip'],
            $prefix.'client.user_agent' => ['nullable', 'string', 'max:512'],
            $prefix.'client.ga_client_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
            $prefix.'client.ga_session_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
            $prefix.'client.fbp' => ['nullable', 'string', 'max:255'],
            $prefix.'client.fbc' => ['nullable', 'string', 'max:255'],
            $prefix.'consent' => [$presence, 'array:analytics,ads,mode,policy_version'],
            $prefix.'consent.analytics' => ['required_with:'.$prefix.'consent', 'boolean'],
            $prefix.'consent.ads' => ['required_with:'.$prefix.'consent', 'boolean'],
            $prefix.'consent.mode' => ['required_with:'.$prefix.'consent', 'string', Rule::in(['opt_in', 'notice'])],
            $prefix.'consent.policy_version' => ['nullable', 'string', 'max:32'],
        ];
    }

    /** @param  array<string, mixed>  $client */
    public static function client(array $client): TrackingClientContext
    {
        return TrackingClientContext::fromArray($client);
    }

    /** @param  array<string, mixed>  $consent */
    public static function consent(array $consent): ConsentState
    {
        $mode = $consent['mode'] ?? 'opt_in';
        $version = $consent['policy_version'] ?? null;

        return new ConsentState(
            filter_var($consent['analytics'] ?? false, FILTER_VALIDATE_BOOLEAN),
            filter_var($consent['ads'] ?? false, FILTER_VALIDATE_BOOLEAN),
            is_string($mode) ? $mode : 'opt_in',
            is_string($version) && $version !== '' ? $version : null,
        );
    }
}

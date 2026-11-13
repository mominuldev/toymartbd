<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Tracking\DTOs\DispatchResult;
use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The outcome of one "Send test event" (GA4 validation server / Meta test_event_code).
 * `messages` are the provider's own words: GA4's `validationMessages[].description`
 * (https://developers.google.com/analytics/devguides/collection/protocol/ga4/validating-events),
 * Meta's `messages[]` or `error.message`
 * (https://developers.facebook.com/docs/marketing-api/conversions-api/using-the-api).
 * Everything here was scrubbed of secrets by the driver ({@see DispatchResult}).
 *
 * @property array{destination: string, result: DispatchResult} $resource
 */
#[SchemaName('TrackingTestResult')]
final class TrackingTestResultResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $result = $this->resource['result'];

        return [
            /** @var 'ga4'|'meta' */
            'destination' => $this->resource['destination'],
            /** @var bool True when the provider accepted the event with no validation message. */
            'valid' => $result->status === DispatchStatus::Sent,
            /** @var 'pending'|'sent'|'failed'|'skipped_consent'|'skipped_disabled'|'skipped_stale'|'not_applicable' */
            'status' => $result->status->value,
            /** @var int|null */
            'http_status' => $result->httpStatus,
            /** @var 'not_configured'|'meta_test_code_missing'|'unreachable'|null A known, non-provider reason the admin shows in its own words (EN/BN); null = see `messages`. */
            'reason' => self::reason($this->resource['destination'], $result),
            /** @var list<string> The provider's own words (not translated). Empty when `reason` explains it. */
            'messages' => self::reason($this->resource['destination'], $result) === null ? self::messages($result) : [],
            /** @var string|null At most 2 KB of the provider's response, secrets removed. */
            'response_excerpt' => $result->responseExcerpt,
        ];
    }

    /**
     * Our own (not the provider's) reasons for a test that never reached a provider answer:
     * SendTestTrackingEvent / the drivers phrase these in English only, so the admin maps
     * the code to an i18n key instead of showing the English text.
     */
    private static function reason(string $destination, DispatchResult $result): ?string
    {
        if ($result->status === DispatchStatus::SkippedDisabled) {
            return 'not_configured';
        }
        if ($result->status === DispatchStatus::Failed && $result->httpStatus === null) {
            return $destination === 'meta' && app(TrackingSettings::class)->metaTestEventCode() === null ? 'meta_test_code_missing' : 'unreachable';
        }

        return null;
    }

    /**
     * The driver's own words first: GA4's validate() puts each validation message in
     * `error` as `CODE fieldPath: description`, joined by " | ". Otherwise (a Meta answer, or
     * an accepted event that still carries notes) the response's `messages[]` /
     * `error.message`.
     *
     * @return list<string>
     */
    private static function messages(DispatchResult $result): array
    {
        $messages = [];

        if ($result->status !== DispatchStatus::Sent && is_string($result->error) && $result->error !== '') {
            $error = (string) preg_replace('/^GA4 validation:\s*/', '', $result->error);
            foreach (explode(' | ', $error) as $line) {
                if (trim($line) !== '') {
                    $messages[] = trim($line);
                }
            }

            return array_values(array_unique($messages));
        }

        $json = is_string($result->responseExcerpt) ? json_decode($result->responseExcerpt, true) : null;
        if (is_array($json)) {
            foreach (is_array($json['messages'] ?? null) ? $json['messages'] : [] as $message) {
                if (is_string($message) && $message !== '') {
                    $messages[] = $message;
                } elseif (is_array($message) && is_string($message['message'] ?? null)) {
                    $messages[] = $message['message'];
                }
            }
            if (is_array($json['error'] ?? null) && is_string($json['error']['message'] ?? null)) {
                $messages[] = $json['error']['message'];
            }
        }

        return array_values(array_unique($messages));
    }
}

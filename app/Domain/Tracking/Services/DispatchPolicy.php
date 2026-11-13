<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Services;

use App\Domain\Tracking\Contracts\TrackingDestination;
use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Enums\TrackingEventName;
use App\Domain\Tracking\Enums\TrackingSource;

/**
 * THE one place that decides whether the server sends an event to a destination
 * (docs/api/tracking.md §1). Returns null for "send", otherwise the terminal status
 * the row records instead. Checks run in this order:
 *
 * 1. Dedup matrix → `not_applicable`. Meta deduplicates browser + server on event_id +
 *    event_name, so the server relays every Meta-mapped event (not page_view, not refund —
 *    Meta has no refund event). GA4's Measurement Protocol has NO deduplication, so each
 *    GA4 event has exactly one sender: the browser (gtag) sends view_item / add_to_cart /
 *    begin_checkout / page_view, the server sends only purchase and refund.
 * 2. Configuration → `skipped_disabled`: server-side tracking off, no driver, or the
 *    destination is not enabled / has no credentials.
 * 3. Consent → `skipped_consent`: GA4 needs analytics consent, Meta needs ads consent.
 *    A refused consent is never sent, not even "anonymised".
 */
final readonly class DispatchPolicy
{
    /** GA4 events the server sends; every other GA4 event is the browser's. */
    public const array GA4_SERVER_EVENTS = [TrackingEventName::Purchase, TrackingEventName::Refund];

    public function __construct(private TrackingSettings $settings) {}

    public function decide(
        TrackingEventName $name,
        TrackingSource $source,
        string $destinationKey,
        ?TrackingDestination $destination,
        ConsentState $consent,
    ): ?DispatchStatus {
        if (! self::applies($name, $source, $destinationKey)) {
            return DispatchStatus::NotApplicable;
        }
        if (! $this->settings->serverSideEnabled() || $destination === null || ! $destination->isConfigured()) {
            return DispatchStatus::SkippedDisabled;
        }
        if (! self::consentAllows($destinationKey, $consent) || ! $destination->requiresConsent($consent)) {
            return DispatchStatus::SkippedConsent;
        }

        return null;
    }

    /** The dedup matrix (docs/api/tracking.md §1.2). */
    public static function applies(TrackingEventName $name, TrackingSource $source, string $destinationKey): bool
    {
        return match ($destinationKey) {
            'ga4' => $source === TrackingSource::Server && in_array($name, self::GA4_SERVER_EVENTS, true),
            'meta' => $name !== TrackingEventName::PageView && $name->metaName() !== null,
            default => false,
        };
    }

    public static function consentAllows(string $destinationKey, ConsentState $consent): bool
    {
        return match ($destinationKey) {
            'ga4' => $consent->analytics,
            'meta' => $consent->ads,
            default => false,
        };
    }

    /**
     * Consent for an order event with no browser consent on record: a staff-entered order
     * (phone, Facebook, WhatsApp, admin) or a web checkout that sent no `tracking` object.
     * In `notice` mode the store's notice covers it, so both purposes are granted; in
     * `opt_in` mode nobody opted in, so nothing is sent (skipped_consent).
     */
    public function serverConsent(): ConsentState
    {
        $notice = $this->settings->consentMode() === 'notice';

        return new ConsentState($notice, $notice, $this->settings->consentMode(), $this->settings->consentPolicyVersion());
    }

    /** @param  array<string, mixed>|null  $stored */
    public static function consentFromArray(?array $stored): ?ConsentState
    {
        if ($stored === null || ! array_key_exists('analytics', $stored) || ! array_key_exists('ads', $stored)) {
            return null;
        }
        $mode = $stored['mode'] ?? 'opt_in';
        $version = $stored['policy_version'] ?? null;

        return new ConsentState(
            (bool) $stored['analytics'],
            (bool) $stored['ads'],
            is_string($mode) ? $mode : 'opt_in',
            is_string($version) ? $version : null,
        );
    }

    /** @return array{analytics: bool, ads: bool, mode: string, policy_version: string|null} */
    public static function consentToArray(ConsentState $consent): array
    {
        return ['analytics' => $consent->analytics, 'ads' => $consent->ads, 'mode' => $consent->mode, 'policy_version' => $consent->policyVersion];
    }
}

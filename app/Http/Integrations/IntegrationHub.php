<?php

declare(strict_types=1);

namespace App\Http\Integrations;

use App\Domain\Ai\Enums\AiProvider;
use App\Domain\Ai\Services\AiSettings;
use App\Domain\Fulfilment\Drivers\Pathao\PathaoCredentials;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\CourierCircuitBreaker;
use App\Domain\Fulfilment\Support\CourierCredentialFields;
use App\Domain\Inbox\Services\InboxChannels;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Services\MessagingSettings;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Services\OnlineGateways;
use App\Domain\Payment\Support\GatewayCredentials;
use App\Domain\Tracking\Queries\TrackingDiagnostics;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Admin → Integrations hub (Figma B · 37 `241:50294`): one status per outside service, every
 * one DERIVED from state the codebase already holds — courier rows, the payment switchboard,
 * the messaging and tracking settings, the notification and tracking logs. Nothing here is
 * cached (a credential fixed on a settings screen must show on the next load) and nothing
 * here returns a credential: only whether it is set, as a note code.
 *
 * Online payment gateways (SSLCommerz, PayStation) are configured on Integrations › Payments
 * (`/integrations/payments`, owner request 2026-11-19: keys per mode, sandbox or live switched
 * in the admin); verifying a gateway for live money stays an owner action on the CLI.
 *
 * A service with no driver in the codebase (RedX, bKash, Nagad, Google Search Console) is
 * listed as `not_available` — honest "not built yet", never "not connected", which would
 * imply the owner could connect it today. No accounting provider is named anywhere in the
 * docs, so that category is omitted rather than invented. No balance, token expiry or count is reported that the database does not hold.
 *
 * Statuses: `connected_live` · `test_mode` · `needs_attention` · `not_connected` · `not_available`.
 * `notes` and `attention` are stable codes; the admin renders them through i18n.
 *
 * @phpstan-type Integration array{key: string, name: string, category: string, status: string, notes: list<string>, attention: ?string, bookings_count: ?int, enabled: ?bool, courier_provider_id: ?int, can_toggle: bool, settings_path: ?string}
 */
final readonly class IntegrationHub
{
    public const array CATEGORIES = ['couriers', 'payments', 'messaging', 'marketing', 'ai'];

    public const array STATUSES = ['connected_live', 'test_mode', 'needs_attention', 'not_connected', 'not_available'];

    /** Couriers with no driver yet (PRD F-01: "RedX and Carrybee are plausible additions"). */
    private const array COURIERS_WITHOUT_DRIVER = ['redx' => 'RedX'];

    /** How long a failure stays "recent" for `needs_attention`. */
    private const int RECENT_HOURS = 24;

    public function __construct(
        private CourierCircuitBreaker $breaker,
        private OnlineGateways $gateways,
        private MessagingSettings $messaging,
        private TrackingSettings $tracking,
        private TrackingDiagnostics $diagnostics,
        private InboxChannels $inbox,
        private AiSettings $ai,
    ) {}

    /** @return list<Integration> */
    public function all(?User $user): array
    {
        return [
            ...$this->couriers($user),
            ...$this->payments(),
            ...$this->messagingServices(),
            ...$this->marketing(),
            ...$this->aiProviders(),
        ];
    }

    /**
     * AI providers (owner request 2026-11-17): connected once a key is saved and its last check
     * passed; needs attention when the provider rejected it on the last check.
     *
     * @return list<Integration>
     */
    private function aiProviders(): array
    {
        $items = [];
        foreach (AiProvider::cases() as $provider) {
            $meta = $this->ai->meta($provider);
            $status = match (true) {
                ! $this->ai->connected($provider) => 'not_connected',
                $meta['ok'] === false => 'needs_attention',
                default => 'connected_live',
            };
            $items[] = $this->item($provider->value, $provider->label(), 'ai', $status, [], $status === 'needs_attention' ? 'ai_key_rejected' : null, path: '/integrations/ai');
        }

        return $items;
    }

    /** @return list<Integration> */
    private function couriers(?User $user): array
    {
        $rows = CourierProvider::query()->orderBy('position')->orderBy('id')->get();
        $bookings = Shipment::query()
            ->whereNotNull('consignment_id')
            ->selectRaw('provider_id, count(*) as aggregate')
            ->groupBy('provider_id')
            ->pluck('aggregate', 'provider_id');

        $items = [];
        foreach ($rows as $p) {
            $complete = CourierCredentialFields::complete($p);
            $sandbox = $p->code === 'pathao' && PathaoCredentials::sandboxMode($p);
            $notes = [];
            $attention = null;

            if ($p->code === 'steadfast') {
                // Rule 33a: Steadfast publishes no sandbox; the driver is tested on fixtures only.
                $notes[] = 'no_sandbox';
            }
            if (! $complete) {
                $notes[] = 'no_credentials';
            } elseif ($sandbox) {
                $notes[] = 'sandbox';
            }
            if (! $p->verified) {
                $notes[] = 'unverified_booking_blocked';
            }
            if ($complete && $p->supports_webhook && ($p->webhook_secret === null || $p->webhook_secret === '')) {
                $notes[] = 'webhook_secret_missing';
            }
            $count = (int) ($bookings[$p->id] ?? 0);
            if ($count > 0) {
                $notes[] = 'bookings';
            }

            if ($this->breaker->isOpen($p->code)) {
                $attention = 'courier_unreachable';
            }

            $status = match (true) {
                $attention !== null => 'needs_attention',
                ! $complete => 'not_connected',
                $sandbox || ! $p->verified => 'test_mode',
                default => 'connected_live',
            };

            $items[] = $this->item(
                key: $p->code,
                name: $p->name,
                category: 'couriers',
                status: $status,
                notes: $notes,
                attention: $attention,
                bookings: $count > 0 ? $count : null,
                enabled: $p->is_active,
                providerId: $p->id,
                canToggle: $user !== null && $user->can('updateRates', $p),
                path: '/integrations/couriers',
            );
        }

        foreach (self::COURIERS_WITHOUT_DRIVER as $code => $name) {
            if (! $rows->contains('code', $code)) {
                $items[] = $this->notAvailable($code, $name, 'couriers');
            }
        }

        return $items;
    }

    /**
     * Payments: COD, then each online gateway. A gateway the admin manages keys for
     * (SSLCommerz, PayStation — `GatewayCredentials::methods()`) is judged on its CURRENT mode
     * only, as `OnlineGateways::status()` reports it: no complete key set for that mode →
     * not connected; sandbox → test mode; live but not yet verified by the owner (a CLI
     * action after a real live payment) → still test mode, because production refuses it;
     * live and verified → connected · live.
     *
     * @return list<Integration>
     */
    private function payments(): array
    {
        $items = [
            // Rule 33: COD is a first-class driver, always on and the checkout default.
            $this->item('cod', 'Cash on delivery', 'payments', 'connected_live', ['cod_always_on']),
        ];

        $names = [
            PaymentMethod::Sslcommerz->value => 'SSLCommerz',
            PaymentMethod::Paystation->value => 'PayStation',
            PaymentMethod::Bkash->value => 'bKash',
            PaymentMethod::Nagad->value => 'Nagad',
        ];
        $managed = array_map(fn (PaymentMethod $m): string => $m->value, GatewayCredentials::methods());

        foreach ([PaymentMethod::Sslcommerz, PaymentMethod::Paystation, PaymentMethod::Bkash, PaymentMethod::Nagad] as $method) {
            $s = $this->gateways->status($method);
            $name = $names[$method->value];
            if (! $s['registered']) {
                $items[] = $this->notAvailable($method->value, $name, 'payments');

                continue;
            }

            $notes = [match (true) {
                ! $s['configured'] => 'no_credentials',
                $s['sandbox'] => 'sandbox',
                ! $s['verified'] => 'live_unverified',
                default => 'live',
            }];
            if (! $s['active']) {
                $notes[] = 'switched_off';
            }

            $status = match (true) {
                ! $s['configured'] => 'not_connected',
                $s['sandbox'] || ! $s['verified'] => 'test_mode',
                default => 'connected_live',
            };

            $items[] = $this->item(
                $method->value,
                $name,
                'payments',
                $status,
                $notes,
                enabled: $s['active'],
                path: in_array($method->value, $managed, true) ? '/integrations/payments' : null,
            );
        }

        return $items;
    }

    /** @return list<Integration> */
    private function messagingServices(): array
    {
        $path = '/integrations/messaging';
        $driver = $this->messaging->smsDriver();
        $notes = ['driver_'.$driver];
        $attention = null;

        if ($driver === 'reve') {
            $reve = $this->messaging->reve();
            $complete = $reve['base_url'] !== null && $reve['api_key'] !== null && $reve['secret_key'] !== null && $reve['sender_id'] !== null;
            if (! $complete) {
                $attention = 'sms_credentials_missing';
            } elseif ($this->lastSmsFailed()) {
                $attention = 'sms_last_send_failed';
            }
            $status = $attention !== null ? 'needs_attention' : 'connected_live';
        } else {
            // log / null send nothing to a phone; sms_net_bd is an unverified skeleton (env only).
            $status = $driver === 'sms_net_bd' ? 'test_mode' : 'not_connected';
        }

        $items = [$this->item('sms', 'SMS gateway', 'messaging', $status, $notes, $attention, path: $path)];

        // Order notifications: switched on with the log / null WhatsApp driver, every message is
        // swallowed and the SMS fallback never fires — the customer gets nothing. With `cloud`
        // they go through the Inbox's number, so the row is the Inbox channel's (never verified).
        $inbox = collect($this->inbox->summary())->keyBy('channel');
        if ($this->messaging->whatsappEnabled() && $this->messaging->whatsappDriver() !== 'cloud') {
            $items[] = $this->item('whatsapp', 'WhatsApp Business', 'messaging', 'needs_attention', ['no_driver'], 'whatsapp_on_without_driver', enabled: true, path: $path);
        } else {
            $items[] = $this->inboxChannel('whatsapp', 'WhatsApp Business', $inbox->get('whatsapp'));
        }
        // Inbox channels (P10): Meta drivers exist but no account does — never more than test mode.
        $items[] = $this->inboxChannel('messenger', 'Facebook Messenger', $inbox->get('messenger'));
        // The live-chat channel has no storefront widget and no inbound path yet.
        $items[] = $this->notAvailable('live_chat', 'Live chat', 'messaging');

        return $items;
    }

    /** @return list<Integration> */
    private function marketing(): array
    {
        $path = '/integrations/tracking';
        $health = [];
        foreach ($this->diagnostics->summary()['destinations'] as $row) {
            $health[$row['destination']] = $row;
        }
        $serverSide = $this->tracking->serverSideEnabled();

        // Meta Conversions API (and the pixel).
        $metaOn = $this->tracking->metaEnabled();
        $metaToken = $this->tracking->secretSource(TrackingSettings::META_ACCESS_TOKEN) !== null;
        $metaNotes = [];
        $metaAttention = null;
        if ($metaOn) {
            if ($this->tracking->metaPixelId() === null) {
                $metaAttention = 'pixel_id_missing';
            } elseif ($serverSide && $metaToken && $this->failing($health['meta'] ?? null)) {
                $metaAttention = 'tracking_send_failing';
            }
            $metaNotes[] = $serverSide && $metaToken ? 'server_and_browser' : 'browser_only';
            if ($this->tracking->metaTestEventCode() !== null) {
                $metaNotes[] = 'test_event_code';
            }
        } else {
            $metaNotes[] = 'switched_off';
        }
        $metaStatus = match (true) {
            $metaAttention !== null => 'needs_attention',
            ! $metaOn => 'not_connected',
            $this->tracking->metaTestEventCode() !== null => 'test_mode',
            default => 'connected_live',
        };

        // Google Analytics 4 (Measurement Protocol + gtag).
        $ga4On = $this->tracking->ga4Enabled();
        $ga4Secret = $this->tracking->secretSource(TrackingSettings::GA4_API_SECRET) !== null;
        $ga4Notes = [];
        $ga4Attention = null;
        if ($ga4On) {
            if ($this->tracking->ga4MeasurementId() === null) {
                $ga4Attention = 'measurement_id_missing';
            } elseif ($serverSide && $ga4Secret && $this->failing($health['ga4'] ?? null)) {
                $ga4Attention = 'tracking_send_failing';
            }
            $ga4Notes[] = $serverSide && $ga4Secret ? 'server_and_browser' : 'browser_only';
        } else {
            $ga4Notes[] = 'switched_off';
        }
        $ga4Status = match (true) {
            $ga4Attention !== null => 'needs_attention',
            ! $ga4On => 'not_connected',
            default => 'connected_live',
        };

        $gtm = $this->tracking->gtmContainerId() !== null;

        return [
            $this->item('meta_capi', 'Meta Conversions API', 'marketing', $metaStatus, $metaNotes, $metaAttention, enabled: $metaOn, path: $path),
            $this->item('ga4', 'Google Analytics 4', 'marketing', $ga4Status, $ga4Notes, $ga4Attention, enabled: $ga4On, path: $path),
            $this->item('gtm', 'Google Tag Manager', 'marketing', $gtm ? 'connected_live' : 'not_connected', [$gtm ? 'container_set' : 'no_container'], path: $path),
            $this->notAvailable('search_console', 'Google Search Console', 'marketing'),
        ];
    }

    /** The most recent SMS attempt in the last 24 h failed. */
    private function lastSmsFailed(): bool
    {
        $last = NotificationLog::query()
            ->where('channel', NotificationChannel::Sms->value)
            ->whereIn('status', [NotificationStatus::Sent->value, NotificationStatus::Failed->value, NotificationStatus::DeadLettered->value])
            ->where('created_at', '>=', CarbonImmutable::now()->subHours(self::RECENT_HOURS))
            ->orderByDesc('id')
            ->first();

        return $last !== null && $last->status !== NotificationStatus::Sent;
    }

    /**
     * A destination is failing when its last error is recent and newer than its last success.
     *
     * @param  array{last_success_at: ?CarbonImmutable, last_error_at: ?CarbonImmutable}|null  $health
     */
    private function failing(?array $health): bool
    {
        $error = $health['last_error_at'] ?? null;
        if ($error === null || $error->lt(CarbonImmutable::now()->subHours(self::RECENT_HOURS))) {
            return false;
        }
        $success = $health['last_success_at'] ?? null;

        return $success === null || $error->gt($success);
    }

    /** @return Integration */
    private function notAvailable(string $key, string $name, string $category): array
    {
        return $this->item($key, $name, $category, 'not_available', ['no_driver']);
    }

    /**
     * An inbox channel: credentials and webhook secrets entered → `test_mode` (built against
     * Meta's published API, unverified until a real account is seen working); otherwise
     * `not_connected`. Never `connected_live` while the driver reports unverified.
     *
     * @param  array{channel: string, driver: string, verified: bool, webhook_ready: bool, send_ready: bool}|null  $summary
     * @return Integration
     */
    private function inboxChannel(string $key, string $name, ?array $summary): array
    {
        if ($summary === null) {
            return $this->notAvailable($key, $name, 'messaging');
        }
        if ($summary['verified'] && $summary['send_ready'] && $summary['webhook_ready']) {
            return $this->item($key, $name, 'messaging', 'connected_live', ['inbox_channel'], path: '/inbox');
        }

        return $summary['send_ready'] && $summary['webhook_ready']
            ? $this->item($key, $name, 'messaging', 'test_mode', ['inbox_unverified'], path: '/inbox')
            : $this->item($key, $name, 'messaging', 'not_connected', ['inbox_not_configured']);
    }

    /**
     * @param  list<string>  $notes
     * @return Integration
     */
    private function item(
        string $key,
        string $name,
        string $category,
        string $status,
        array $notes = [],
        ?string $attention = null,
        ?int $bookings = null,
        ?bool $enabled = null,
        ?int $providerId = null,
        bool $canToggle = false,
        ?string $path = null,
    ): array {
        return [
            'key' => $key,
            'name' => $name,
            'category' => $category,
            'status' => $status,
            'notes' => $notes,
            'attention' => $attention,
            'bookings_count' => $bookings,
            'enabled' => $enabled,
            'courier_provider_id' => $providerId,
            'can_toggle' => $canToggle,
            'settings_path' => $path,
        ];
    }
}

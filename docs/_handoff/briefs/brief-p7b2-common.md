# Common rules for every Phase 7 batch-2 agent (toymartbd) — server-side tracking

Repo: `/Users/mominul/Sites/toymartbd` (Laravel 13 at root; admin SPA `resources/js/admin/`; Next.js storefront `toymartbd-app/storefront/`). Branch `phase-7/batch-2` is checked out. **THREE OTHER AGENTS edit this same working tree at the same time.**

## Machine (changed since batch 1 — ignore the old /Volumes paths)
- PHP 8.4 on PATH (`php`). Node 26 on PATH works. `vendor/` and `node_modules/` are installed — do **not** run `composer install` / `npm install` unless you add a dependency (then say so).
- **Disk is ~3.5 GB free.** Never leave build output behind: delete any `.next`/`dist` dirs, `test-results/`, `playwright-report/` you create. No storefront `next build` unless your brief says so.
- Temp files: your own folder under `$TMPDIR` (e.g. `$TMPDIR/agent-N/`).
- The owner runs `php artisan serve` on :8000 and Vite on :5173 from this tree — never kill them, never delete `public/hot`, never `migrate:fresh` the default DB. Use your own SQLite file + port for anything you run (`DB_CONNECTION=sqlite DB_DATABASE=$TMPDIR/agent-N/db.sqlite CACHE_STORE=file QUEUE_CONNECTION=sync SESSION_DRIVER=file`).
- Admin bundle build, if you need one: wrap it in `until mkdir $TMPDIR/admin-build.lock 2>/dev/null; do sleep 5; done; npm run build; rmdir $TMPDIR/admin-build.lock`. Never `npm run dev`.

## Rules
- Read `CLAUDE.md` (non-negotiable rules) first, then `docs/SYSTEM-ARCHITECTURE.md` §7 (queues: `tracking` queue, 3 tries, never retried past 1 h) and §8 "Server-side tracking", `docs/DATABASE-DESIGN.md` (`tracking_events`), `docs/PRODUCT-REQUIREMENTS.md` K-01..K-03, `docs/DEVELOPMENT-ROADMAP.md` Phase 7 testing line, `docs/_design/06-admin-feature-checklist.md` §2.12, `docs/_research/agent4-bd-market-and-payments.md` §7.10 rows 3–4 (cookie/tracking disclosure and prior consent are required in BD).
- **NO git commands at all.** The lead does all git.
- Stay strictly inside your file scope. Shared files (`routes/api.php`, `deptrac.yaml`, `config/*`, `app/Providers/*ServiceProvider.php`, storefront message catalogues, admin `app/routes.tsx` / nav, admin i18n message files, `phpunit.xml`, `database/seeders/RolesAndPermissionsSeeder.php`) may be touched only with small surgical Edit calls — re-read right before editing, never rewrite. Do NOT edit `docs/IMPLEMENTATION-PLAN.md`; report progress in your final message.
- Migrations: new files only, named `2026_11_29_NNNNNN_*` inside the N-block in your brief.
- Follow existing patterns: Actions (one use case, the only place for `DB::transaction`), FormRequests, API Resources, Policies, RFC 9457 problems, enums for every status, allowlisted filters (unlisted = 400), the i18n layer (EN + BN for every string), semantic tokens only. Drivers for every external provider (rule 5) — see `app/Domain/Notification/Drivers` and `app/Domain/Payment/Gateways` for the house style; `app/Domain/Notification/Services/MessagingSettings` + `Admin/Settings/MessagingSettingsController` for settings with encrypted secrets.
- **No external HTTP inside a DB transaction** (rule 4). Provider calls happen only in queued jobs on the `tracking` queue.
- **Never log a credential, a full phone number, an email or an address** (rule 20). Hashes and masked values only. `tracking_events.payload` stores the payload *as sent*, i.e. with user data already SHA-256 hashed — never raw PII.
- Provider behaviour: use the **official** docs only (Google Analytics Measurement Protocol for GA4 at developers.google.com/analytics/devguides/collection/protocol/ga4; Meta Conversions API at developers.facebook.com/docs/marketing-api/conversions-api). Cite the URL next to every field rule you implement, in `docs/api/tracking.md`. If a doc can't be fetched or is ambiguous, say so and mark the code `// UNVERIFIED:`. Never invent provider behaviour. No real credentials exist; everything is tested with `Http::fake` and fixtures.
- Tests: Pest for PHP (`php artisan test --filter=…` while working; full `php artisan test --parallel` once at the end), Vitest for admin, storefront unit tests (`npm run test:storefront`). Then `./vendor/bin/pint <your paths>`, `./vendor/bin/phpstan analyse`, `./vendor/bin/deptrac`, `npm run typecheck` / `npm run typecheck:storefront`, `npm run lint:i18n`. Failures in other agents' in-progress files: note them, don't fix them.
- After changing any FormRequest/Resource: `composer openapi:export` then `npm run api-types:generate` (others do too; re-run if stale).
- Do NOT run admin Playwright E2E (port 8124 collides). Storefront Playwright only if your brief says so, on your own ports.
- UI: owner requires Figma fidelity. File `PWZp5VzEgEEf5N2Y38lGIh`, admin Theme B section `241:29517`. Load the `/figma-design-to-code` skill, `create_session`, then `get_screenshot` / `get_design_context`. **READ ONLY — never write to Figma.** A value without a token gets reported, not invented.
- A task is not done until its tests pass. **Final message:** files built, tests added with exact pass counts, commands run and their status, Figma differences remaining, owner decisions / open questions, anything unfinished.

## Shared contract (fixed — code against it; agent 2 creates these files FIRST)
```php
namespace App\Domain\Tracking\Enums;
enum TrackingEventName: string { case PageView='page_view'; case ViewItem='view_item'; case AddToCart='add_to_cart'; case BeginCheckout='begin_checkout'; case Purchase='purchase'; case Refund='refund'; }
//   ->ga4Name(): string (same as value), ->metaName(): ?string  (ViewContent, AddToCart, InitiateCheckout, Purchase; page_view→PageView; refund→null = not sent to Meta)
enum TrackingSource: string { case Browser='browser'; case Server='server'; }
enum DispatchStatus: string { case Pending='pending'; case Sent='sent'; case Failed='failed'; case SkippedConsent='skipped_consent'; case SkippedDisabled='skipped_disabled'; case SkippedStale='skipped_stale'; case NotApplicable='not_applicable'; }

namespace App\Domain\Tracking\DTOs;
final readonly class TrackingEventData {      // everything a destination needs, PII still RAW here (drivers hash)
  public function __construct(
    public string $eventId,                  // UUID, shared with the browser event
    public TrackingEventName $name,
    public \Carbon\CarbonImmutable $occurredAt,
    public ?string $currency,                // 'BDT'
    public ?string $value,                   // decimal string, 2dp — never float
    public array $items,                     // list<array{item_id:string,item_name:string,price:string,quantity:int,item_brand?:string,item_category?:string,item_variant?:string}>
    public ?string $transactionId,           // order number for purchase/refund
    public ?string $eventSourceUrl,
    public ?string $clientIp, public ?string $userAgent,
    public ?string $gaClientId, public ?string $gaSessionId,
    public ?string $fbp, public ?string $fbc,
    public ?string $phoneE164, public ?string $email, public ?string $firstName, public ?string $lastName,
    public ?string $city, public ?string $externalId,   // customer id or phone-derived id
    public ConsentState $consent,
  ) {}
}
final readonly class ConsentState { public function __construct(public bool $analytics, public bool $ads, public string $mode /* opt_in|notice */, public ?string $policyVersion = null) {} }
final readonly class DispatchResult { public function __construct(public DispatchStatus $status, public ?int $httpStatus = null, public ?string $responseExcerpt /* ≤2 KB, no secrets */ = null, public array $sentPayload = [] /* as sent, PII hashed, no secrets */, public ?string $error = null) {} }

namespace App\Domain\Tracking\Contracts;
interface TrackingDestination {
  public function key(): string;              // 'ga4' | 'meta'
  public function isConfigured(): bool;        // credentials present + enabled
  public function requiresConsent(ConsentState $c): bool; // ga4 → analytics, meta → ads
  public function send(TrackingEventData $e, bool $testMode = false): DispatchResult; // never throws on 4xx; throws only for retryable transport/5xx/429
  public function validate(TrackingEventData $e): DispatchResult; // GA4 debug endpoint / Meta test_event_code; for the admin "Send test event"
}
```
Settings service (agent 1 creates it FIRST, others read it): `App\Domain\Tracking\Services\TrackingSettings` — `ga4MeasurementId()`, `ga4ApiSecret()` (encrypted at rest), `ga4Enabled()`, `metaPixelId()`, `metaAccessToken()` (encrypted), `metaTestEventCode()`, `metaEnabled()`, `metaGraphVersion()` (config default), `gtmContainerId()`, `purchaseTrigger(): PurchaseTrigger` (enum `order_placed`|`order_delivered`, default `order_placed`), `consentMode()` (`opt_in` default | `notice`), `consentPolicyVersion()`, `serverSideEnabled()`, `publicConfig(): array` (only the public ids + flags, never secrets). Store it the way `MessagingSettings` stores messaging settings.

Storefront ↔ API contract (agent 1 builds the API, agent 4 consumes it; storefront calls it server-side only, with the existing storefront bearer token — rule 28):
- `GET /api/v1/storefront/tracking/config` → `{ ga4_measurement_id, meta_pixel_id, gtm_container_id, consent_mode, consent_policy_version, purchase_trigger, enabled: {ga4, meta} }` (cache tag `tracking-config`; the admin settings save revalidates it through the existing revalidation mechanism).
- `POST /api/v1/storefront/tracking/events` → body `{ event_id (uuid), event_name, occurred_at, event_source_url, value?, currency?, items?, client: { ip, user_agent, ga_client_id?, ga_session_id?, fbp?, fbc? }, consent: { analytics, ads, mode, policy_version } }` → `202 {event_id}`. Records a `browser`-mirrored row and dispatches the server twin. Idempotent on `(event_id, event_name)`. Allowed names: view_item, add_to_cart, begin_checkout (purchase is server-originated only). Rate-limited.
- Checkout: `CheckoutRequest` gains an **optional** `tracking` object (same `client` + `consent` shape). The checkout controller, after `PlaceOrder` has committed, calls Tracking's `RecordOrderTrackingContext` (never inside the transaction). The order confirmation resource gains `tracking.purchase_event_id` (deterministic UUIDv5 of `purchase:{order_number}` so browser and server agree) and `tracking.fire_browser_purchase` (true only when `purchase_trigger = order_placed`).

Dedup rule: Meta dedups on `event_id` + `event_name`; GA4 MP has no dedup, so for GA4 **only one channel sends each event**: the browser sends view_item/add_to_cart/begin_checkout to GA4 when gtag loaded; the server sends `purchase`/`refund` to GA4. Server-to-Meta sends everything with the browser's event_id. Agent 1 encodes this matrix in one place (`DispatchPolicy`) with tests.

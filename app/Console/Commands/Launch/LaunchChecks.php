<?php

declare(strict_types=1);

namespace App\Console\Commands\Launch;

use App\Console\Commands\Backup\BackupCatalog;
use App\Console\Commands\Backup\RehearsalLog;
use App\Console\Commands\IssueStorefrontToken;
use App\Console\Commands\Monitoring\SchedulerHeartbeatCommand;
use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Customer\Models\Customer;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Services\BookingPreflight;
use App\Domain\Fulfilment\Services\DeliverySettings;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Notification\Services\MessagingSettings;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Services\OnlineGateways;
use App\Domain\Risk\Services\RiskSettings;
use App\Domain\Seo\Services\RobotsTxt;
use App\Domain\Seo\Services\SitemapBuilder;
use App\Models\Enums\Role;
use App\Models\Setting;
use App\Models\SystemHeartbeat;
use App\Models\User;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoCustomersSeeder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use PDO;
use Throwable;

/**
 * Every automatable item of the Phase 10 launch checklist (DEVELOPMENT-ROADMAP §Phase 10,
 * docs/ops/LAUNCH-CHECKLIST.md), plus the manual items as pointers. Read-only: nothing here
 * writes, sends, books or contacts a provider. Secrets are checked for presence and against
 * the repo's known placeholder values, and reported by name only — never their value.
 *
 * Each check is a method returning one {@see CheckResult}; {@see self::run()} turns an
 * exception inside a check into a FAIL for that check, so one broken dependency (Redis down)
 * never hides the rest of the report.
 */
final class LaunchChecks
{
    /** Placeholder values that ship in .env.example / config defaults and must never reach production. */
    public const array PLACEHOLDER_SECRETS = ['', 'null', 'secret', 'password', 'root', 'changeme', 'change-me', 'local-master-key-change-me'];

    /** The seeded hotline (config/notifications.php, .env.example) and the design's sample numbers. */
    public const array PLACEHOLDER_HOTLINES = ['09600123456', '09610123456', '01712345678'];

    /** Copy markers that must not appear on a published policy page (StaticPagesSeeder writes "PLACEHOLDER"). */
    public const array PLACEHOLDER_MARKERS = ['placeholder', 'lorem ipsum', 'todo:', 'tbd', '[owner'];

    /** The pages the launch checklist requires published with real details (rule-free, roadmap §Phase 10). */
    public const array POLICY_PAGES = ['privacy-policy', 'returns-policy', 'terms', 'contact', 'faq', 'delivery-policy'];

    public const int BACKUP_MAX_AGE_HOURS = 26;

    public const int REHEARSAL_MAX_AGE_DAYS = 35;

    public const int SITEMAP_MAX_AGE_HOURS = 48;

    public const string STOREFRONT_SITE_CONFIG = 'toymartbd-app/storefront/lib/site-config.ts';

    public function __construct(private readonly Application $app) {}

    /**
     * id => check. The order is the checklist's order.
     *
     * @return array<string, Closure(): CheckResult>
     */
    public function checks(): array
    {
        return [
            'env.app_env' => $this->appEnv(...),
            'env.app_debug' => $this->appDebug(...),
            'env.app_url' => $this->appUrl(...),
            'env.https_forced' => fn (): CheckResult => CheckResult::manual('env.https_forced', 'HTTP redirects to HTTPS and the certificate is valid', 'A4', 'Run `curl -sI http://<api-domain>/up` and `curl -sI http://<shop-domain>/` (expect 301 to https://), then `curl -sI https://…` (expect 200, HSTS header). Check certificate expiry with `echo | openssl s_client -connect <domain>:443 2>/dev/null | openssl x509 -noout -enddate`.'),
            'security.session_cookie' => $this->sessionCookie(...),
            'security.app_key' => $this->appKey(...),
            'secrets.required' => $this->requiredSecrets(...),
            'secrets.env_file' => $this->envFile(...),
            'security.trusted_proxies' => $this->trustedProxies(...),
            'app.optimized' => $this->optimized(...),
            'database.connection' => $this->database(...),
            'queue.connection' => $this->queueConnection(...),
            'queue.horizon' => $this->horizon(...),
            'schedule.heartbeat' => $this->scheduler(...),
            'search.driver' => $this->search(...),
            'storefront.token' => $this->storefrontToken(...),
            'app.maintenance' => $this->maintenance(...),
            'backups.configured' => $this->backupsConfigured(...),
            'backups.recent' => $this->backupRecent(...),
            'backups.rehearsal' => $this->rehearsal(...),
            'backups.staging_restore' => fn (): CheckResult => CheckResult::manual('backups.staging_restore', 'A full restore onto staging produced a working app', 'C4', 'Follow docs/ops/backup-and-restore.md "Staging restore" and record date, backup name, duration and result there.'),
            'deploy.rollback' => fn (): CheckResult => CheckResult::manual('deploy.rollback', 'Rollback rehearsed on production', 'C5', 'Deploy a release, roll back with the one-command rollback in docs/ops/DEPLOYMENT.md, confirm /up and the admin, then roll forward. Record the time it took.'),
            'mail.driver' => $this->mail(...),
            'sms.driver' => $this->sms(...),
            'whatsapp.state' => $this->whatsapp(...),
            'couriers.verified' => $this->couriers(...),
            'couriers.rate_card' => $this->rateCards(...),
            'payments.gateway' => $this->payments(...),
            'live.payment' => fn (): CheckResult => CheckResult::manual('live.payment', 'A real online payment completed and refunded', 'D7', 'Place a ৳10–50 order with the live gateway on a real phone, see it Paid in the admin, refund it from the order screen, confirm the refund arrives.'),
            'live.courier' => fn (): CheckResult => CheckResult::manual('live.courier', 'A real courier consignment booked and cancelled', 'D8', 'Book one real order with the verified courier, see the consignment id on the shipment card, cancel it with the courier, confirm the webhook/poll updates the shipment.'),
            'live.messages' => fn (): CheckResult => CheckResult::manual('live.messages', 'Order confirmation SMS, email and WhatsApp received', 'D9', 'On the test order, confirm each message arrived in English and Bangla (Admin → Automations → Message log shows Sent).'),
            'live.checkout' => fn (): CheckResult => CheckResult::manual('live.checkout', 'COD and online checkout both complete on a real phone', 'D10', 'Mid-range Android, mobile data, both locales: one COD and one online order end to end.'),
            'mail.dns' => fn (): CheckResult => CheckResult::manual('mail.dns', 'SPF, DKIM and DMARC published for the sending domain', 'D11', '`dig TXT <domain>`, `dig TXT <selector>._domainkey.<domain>`, `dig TXT _dmarc.<domain>`; send a test to a Gmail address and check "Show original" says PASS for all three.'),
            'seo.robots' => $this->robots(...),
            'seo.sitemap' => $this->sitemap(...),
            'seo.submitted' => fn (): CheckResult => CheckResult::manual('seo.submitted', 'Sitemap submitted to Search Console and Bing', 'E3', 'Search Console → Sitemaps → add /sitemap.xml (and Bing Webmaster Tools); status must be "Success".'),
            'analytics.dedup' => fn (): CheckResult => CheckResult::manual('analytics.dedup', 'GA4 and Meta CAPI receive de-duplicated purchase events', 'E4', 'Place a test order with consent accepted; GA4 DebugView shows one purchase; Meta Events Manager shows browser + server purchase "Deduplicated".'),
            'content.policy_pages' => $this->policyPages(...),
            'business.company_details' => $this->companyDetails(...),
            'business.hotline' => $this->hotline(...),
            'storefront.site_config' => $this->storefrontSiteConfig(...),
            'business.placeholder_values' => $this->placeholderValues(...),
            'content.error_pages' => fn (): CheckResult => CheckResult::manual('content.error_pages', '404 and 500 pages look right in both languages', 'F6', 'Open https://<shop>/no-such-page and /bn/no-such-page (styled 404, HTTP 404), /maintenance and /bn/maintenance, and https://<api>/no-such-page (branded Laravel 404). Automated tests cover the markup; this is the visual check.'),
            'content.legal_display' => fn (): CheckResult => CheckResult::manual('content.legal_display', 'Trade licence and contact details displayed as required', 'F7', 'Check the storefront footer legal bar and the contact page show the trade licence, BIN, address and hotline. Confirm with the owner\'s accountant/lawyer what must be displayed.'),
            'staff.super_admin' => $this->superAdmin(...),
            'staff.roles' => $this->staffRoles(...),
            'data.no_demo' => $this->noDemoData(...),
            'staff.trained' => fn (): CheckResult => CheckResult::manual('staff.trained', 'Staff trained on their screens', 'G4', 'Each staff member completes one real task in their role (confirm an order, book a courier, receive stock) with the owner watching.'),
            'owner.runbook' => fn (): CheckResult => CheckResult::manual('owner.runbook', 'Owner has the runbook', 'G5', 'The owner has read docs/ops/RUNBOOK.md and has the password manager entries it names.'),
            'monitoring.sentry' => $this->sentry(...),
            'monitoring.logs' => $this->logs(...),
            'monitoring.live' => fn (): CheckResult => CheckResult::manual('monitoring.live', 'Sentry receiving, uptime checks green, business alerts fire on a test', 'H3', 'Trigger a test error and see it in Sentry with the release SHA; see the uptime monitor green for /up and the storefront; trigger each business alert\'s test condition (docs/ops/observability.md).'),
        ];
    }

    /**
     * @param  list<string>|null  $only
     * @return list<CheckResult>
     */
    public function run(?array $only = null): array
    {
        $results = [];
        foreach ($this->checks() as $id => $check) {
            if ($only !== null && $only !== [] && ! $this->selected($id, $only)) {
                continue;
            }
            try {
                $results[] = $check();
            } catch (Throwable $e) {
                $results[] = CheckResult::fail($id, $id, '—', 'The check itself failed: '.class_basename($e).' — '.self::scrub($e->getMessage()), 'Fix the dependency named above and run the check again.');
            }
        }

        return $results;
    }

    /** @param list<string> $only */
    private function selected(string $id, array $only): bool
    {
        foreach ($only as $pattern) {
            if ($id === $pattern || str_starts_with($id, rtrim($pattern, '.*').'.')) {
                return true;
            }
        }

        return false;
    }

    // ── A. Environment and security ─────────────────────────────────────────────────────────

    private function appEnv(): CheckResult
    {
        $env = (string) $this->app->environment();

        return $env === 'production'
            ? CheckResult::pass('env.app_env', 'APP_ENV=production', 'A1', 'APP_ENV is production.')
            : CheckResult::fail('env.app_env', 'APP_ENV=production', 'A1', "APP_ENV is `{$env}`.", 'Set APP_ENV=production in the host secret store / shared .env and re-run `php artisan config:cache`.');
    }

    private function appDebug(): CheckResult
    {
        // ProductionSafety forces debug off at boot when APP_DEBUG=true in production and leaves a flag.
        $forced = config('app.debug_forced_off') === true;

        if (config('app.debug') === true || $forced) {
            return CheckResult::fail('env.app_debug', 'APP_DEBUG=false', 'A2', $forced ? 'APP_DEBUG is true in the environment (forced off at boot and logged as critical, but the env is wrong).' : 'app.debug is true.', 'Set APP_DEBUG=false and re-run `php artisan config:cache`.');
        }

        return CheckResult::pass('env.app_debug', 'APP_DEBUG=false', 'A2', 'Debug mode is off.');
    }

    private function appUrl(): CheckResult
    {
        $problems = [];
        foreach (['APP_URL' => config('app.url'), 'STOREFRONT_URL' => config('services.storefront.url')] as $name => $url) {
            $why = self::publicHttpsProblem($url);
            if ($why !== null) {
                $problems[] = "{$name} {$why}";
            }
        }

        return $problems === []
            ? CheckResult::pass('env.app_url', 'APP_URL and STOREFRONT_URL are public https URLs', 'A3', 'Both are https on a public host.')
            : CheckResult::fail('env.app_url', 'APP_URL and STOREFRONT_URL are public https URLs', 'A3', implode('; ', $problems).'.', 'Set them to the production https origins (no trailing slash).');
    }

    private function sessionCookie(): CheckResult
    {
        $problems = [];
        if (config('session.secure') !== true) {
            $problems[] = 'session cookie is not Secure';
        }
        if (config('session.http_only') !== true) {
            $problems[] = 'session cookie is not HttpOnly';
        }
        if (! in_array(strtolower((string) config('session.same_site')), ['lax', 'strict'], true)) {
            $problems[] = 'SameSite is not lax/strict';
        }
        if (config('session.driver') !== 'redis') {
            $problems[] = 'SESSION_DRIVER is `'.config('session.driver').'` (redis expected)';
        }

        return $problems === []
            ? CheckResult::pass('security.session_cookie', 'Secure session cookies', 'A5', 'Secure, HttpOnly, SameSite '.config('session.same_site').', Redis sessions.')
            : CheckResult::fail('security.session_cookie', 'Secure session cookies', 'A5', implode('; ', $problems).'.', 'Unset SESSION_SECURE_COOKIE (secure by default in production) or set it true; SESSION_HTTP_ONLY=true; SESSION_SAME_SITE=lax; SESSION_DRIVER=redis.');
    }

    private function appKey(): CheckResult
    {
        $key = (string) config('app.key');

        return $key !== '' && strlen($key) >= 32
            ? CheckResult::pass('security.app_key', 'APP_KEY set and escrowed', 'A6', 'APP_KEY is set (value not shown). Confirm it is escrowed in the password manager — it is never in a backup.')
            : CheckResult::fail('security.app_key', 'APP_KEY set and escrowed', 'A6', 'APP_KEY is missing.', 'Generate once with `php artisan key:generate --show`, store in the host secret store and the password manager. Never rotate it casually: encrypted credentials become unreadable.');
    }

    private function requiredSecrets(): CheckResult
    {
        $connection = (string) config('database.default');
        /** @var array<string, mixed> $required name => value */
        $required = [
            'DB_PASSWORD' => config("database.connections.{$connection}.password"),
            'BACKUP_ARCHIVE_PASSWORD' => config('backup.archive_password'),
            'STOREFRONT_REVALIDATE_SECRET' => config('services.storefront.revalidate_secret'),
            'REDIS_PASSWORD' => config('database.redis.default.password'),
        ];
        if (config('scout.driver') === 'meilisearch') {
            $required['MEILISEARCH_KEY'] = config('scout.meilisearch.key');
        }

        $missing = [];
        foreach ($required as $name => $value) {
            $string = is_scalar($value) ? trim((string) $value) : '';
            if (in_array(strtolower($string), self::PLACEHOLDER_SECRETS, true)) {
                $missing[] = $name;
            }
        }
        $short = [];
        if (! in_array('BACKUP_ARCHIVE_PASSWORD', $missing, true) && strlen((string) config('backup.archive_password')) < 16) {
            $short[] = 'BACKUP_ARCHIVE_PASSWORD (< 16 characters)';
        }
        if (! in_array('STOREFRONT_REVALIDATE_SECRET', $missing, true) && strlen((string) config('services.storefront.revalidate_secret')) < 32) {
            $short[] = 'STOREFRONT_REVALIDATE_SECRET (< 32 characters)';
        }

        if ($missing === [] && $short === []) {
            return CheckResult::pass('secrets.required', 'Required secrets present', 'A6', 'Present and not a placeholder: '.implode(', ', array_keys($required)).' (values not shown).');
        }

        return CheckResult::fail('secrets.required', 'Required secrets present', 'A6', 'Missing or placeholder: '.implode(', ', [...$missing, ...$short]).'.', 'Set each in the host secret store (`openssl rand -hex 32` for generated ones), then `php artisan config:cache`. Never commit them.');
    }

    private function envFile(): CheckResult
    {
        $path = $this->app->environmentFilePath();
        if (! is_file($path)) {
            return CheckResult::pass('secrets.env_file', 'Secrets come from the host, not the repo', 'A7', 'No .env file in the release; configuration comes from the environment / config cache.');
        }

        $mode = fileperms($path) & 0o777;

        return ($mode & 0o007) === 0
            ? CheckResult::pass('secrets.env_file', 'Secrets come from the host, not the repo', 'A7', sprintf('.env is mode %o (not world-readable). Confirm it is the shared, symlinked copy, not a file from git.', $mode))
            : CheckResult::fail('secrets.env_file', 'Secrets come from the host, not the repo', 'A7', sprintf('.env is mode %o — readable by every user on the host.', $mode), 'chmod 640 the shared .env (owner deploy user, group www-data).');
    }

    private function trustedProxies(): CheckResult
    {
        $proxies = config('trustedproxy.proxies');
        $set = is_array($proxies) ? $proxies !== [] : (is_string($proxies) && trim($proxies) !== '');

        return $set
            ? CheckResult::pass('security.trusted_proxies', 'TRUSTED_PROXIES names the proxy and the Next.js server', 'A8', 'TRUSTED_PROXIES is set. Confirm it lists the reverse proxy and the storefront server egress IP (audit S-15).')
            : CheckResult::fail('security.trusted_proxies', 'TRUSTED_PROXIES names the proxy and the Next.js server', 'A8', 'TRUSTED_PROXIES is empty: every shopper shares the storefront server\'s per-IP bucket for OTP, tracking, coupon and cart limits (SECURITY-REVIEW S-15).', 'Set TRUSTED_PROXIES to the reverse proxy IP and the storefront server\'s egress IP(s); wildcards are refused.');
    }

    private function optimized(): CheckResult
    {
        $missing = [];
        if (! $this->app->configurationIsCached()) {
            $missing[] = 'config';
        }
        if (! $this->app->routesAreCached()) {
            $missing[] = 'routes';
        }

        return $missing === []
            ? CheckResult::pass('app.optimized', 'Config and routes cached', 'A9', 'config:cache and route:cache are in place.')
            : CheckResult::warn('app.optimized', 'Config and routes cached', 'A9', 'Not cached: '.implode(', ', $missing).'.', 'The deploy runs `php artisan optimize`; run it by hand if this is a manual release.');
    }

    private function database(): CheckResult
    {
        $connection = (string) config('database.default');
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql') {
            return CheckResult::fail('database.connection', 'MySQL 8.4 with utf8mb4_0900_ai_ci', 'A10', "The default connection `{$connection}` uses `{$driver}`.", 'Production runs MySQL 8.4 (rule 34). Set DB_CONNECTION=mysql.');
        }
        $collation = (string) config("database.connections.{$connection}.collation");

        return $collation === 'utf8mb4_0900_ai_ci'
            ? CheckResult::pass('database.connection', 'MySQL 8.4 with utf8mb4_0900_ai_ci', 'A10', 'MySQL, collation utf8mb4_0900_ai_ci, server '.self::scrub((string) DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION)).'.')
            : CheckResult::fail('database.connection', 'MySQL 8.4 with utf8mb4_0900_ai_ci', 'A10', "Collation is `{$collation}`.", 'Set DB_COLLATION=utf8mb4_0900_ai_ci (rule 34).');
    }

    // ── B. Runtime ───────────────────────────────────────────────────────────────────────────

    private function queueConnection(): CheckResult
    {
        $queue = (string) config('queue.default');
        $cache = (string) config('cache.default');

        return $queue === 'redis' && $cache === 'redis'
            ? CheckResult::pass('queue.connection', 'Queues and cache on Redis', 'B1', 'QUEUE_CONNECTION=redis, CACHE_STORE=redis.')
            : CheckResult::fail('queue.connection', 'Queues and cache on Redis', 'B1', "QUEUE_CONNECTION={$queue}, CACHE_STORE={$cache}.", 'Set both to redis; Horizon only works on Redis.');
    }

    private function horizon(): CheckResult
    {
        /** @var MasterSupervisorRepository $repository */
        $repository = $this->app->make(MasterSupervisorRepository::class);
        $masters = $repository->all();
        if ($masters === []) {
            return CheckResult::fail('queue.horizon', 'Horizon running', 'B2', 'No Horizon master supervisor is running: queued order messages, courier bookings and search sync would wait for ever.', 'Start Horizon under its process supervisor (docs/ops/DEPLOYMENT.md) — see docs/ops/RUNBOOK.md "Queue backed up".');
        }
        $paused = array_filter($masters, static fn (object $m): bool => ($m->status ?? 'running') !== 'running');

        return $paused === []
            ? CheckResult::pass('queue.horizon', 'Horizon running', 'B2', count($masters).' master supervisor(s) running.')
            : CheckResult::fail('queue.horizon', 'Horizon running', 'B2', 'Horizon is paused.', 'Run `php artisan horizon:continue`.');
    }

    private function scheduler(): CheckResult
    {
        // The one scheduler heartbeat is owned by monitoring: `system_heartbeats.scheduler`.
        $last = SystemHeartbeat::lastSuccess(SystemHeartbeat::SCHEDULER);
        $max = SchedulerHeartbeatCommand::MAX_AGE_MINUTES;
        if ($last === null) {
            return CheckResult::fail('schedule.heartbeat', 'Scheduler running', 'B3', 'No scheduler heartbeat recorded: the cron line is missing or has never fired.', 'Add `* * * * * cd /var/www/toymartbd/current && php artisan schedule:run >> /dev/null 2>&1` to the deploy user\'s crontab (docs/ops/DEPLOYMENT.md).');
        }
        $minutes = (int) $last->diffInMinutes(now(), true);

        return $minutes <= $max
            ? CheckResult::pass('schedule.heartbeat', 'Scheduler running', 'B3', "Last heartbeat {$minutes} min ago.")
            : CheckResult::fail('schedule.heartbeat', 'Scheduler running', 'B3', "Last heartbeat {$minutes} min ago (limit {$max}).", 'Check the cron line and `php artisan schedule:list`; see RUNBOOK "Jobs not running".');
    }

    private function search(): CheckResult
    {
        $driver = (string) config('scout.driver');

        return $driver === 'meilisearch'
            ? CheckResult::pass('search.driver', 'Product search on Meilisearch', 'B4', 'SCOUT_DRIVER=meilisearch.')
            : CheckResult::fail('search.driver', 'Product search on Meilisearch', 'B4', "SCOUT_DRIVER={$driver}: search falls back to the database (no Banglish tolerance).", 'Set SCOUT_DRIVER=meilisearch, then `php artisan toymart:search:configure && php artisan toymart:search:reindex`.');
    }

    private function storefrontToken(): CheckResult
    {
        $account = User::query()->where('email', IssueStorefrontToken::SERVICE_EMAIL)->first();
        $count = $account === null ? 0 : $account->tokens()
            ->where('name', IssueStorefrontToken::TOKEN_NAME)
            ->where(static fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();

        if ($count === 0) {
            return CheckResult::fail('storefront.token', 'Storefront server token issued', 'B5', 'No live storefront token: every storefront API read answers 401.', 'Run `php artisan toymart:storefront-token` once and put the printed value in the storefront\'s STOREFRONT_API_TOKEN (server-only env).');
        }

        return $count === 1
            ? CheckResult::pass('storefront.token', 'Storefront server token issued', 'B5', 'One live storefront token (value never stored in plain text).')
            : CheckResult::warn('storefront.token', 'Storefront server token issued', 'B5', "{$count} live storefront tokens.", 'Rotate to exactly one: `php artisan toymart:storefront-token --rotate`, then update the storefront env.');
    }

    private function maintenance(): CheckResult
    {
        return $this->app->isDownForMaintenance()
            ? CheckResult::warn('app.maintenance', 'Not in maintenance mode', 'B6', 'The API is in maintenance mode (503 to everyone).', 'Run `php artisan up` when the release is verified.')
            : CheckResult::pass('app.maintenance', 'Not in maintenance mode', 'B6', 'The API is up.');
    }

    // ── C. Backups ───────────────────────────────────────────────────────────────────────────

    private function backupsConfigured(): CheckResult
    {
        $disk = (string) config('backup.disk');
        $driver = (string) config("filesystems.disks.{$disk}.driver");
        $password = (string) config('backup.archive_password');
        $problems = [];
        if ($driver !== 's3') {
            $problems[] = "BACKUP_DISK `{$disk}` is a `{$driver}` disk on this host, not off-host";
        }
        if (strlen($password) < 16) {
            $problems[] = 'BACKUP_ARCHIVE_PASSWORD missing or shorter than 16 characters';
        }

        return $problems === []
            ? CheckResult::pass('backups.configured', 'Encrypted off-host backups configured', 'C1', "Backups go to `{$disk}` (S3-compatible), encrypted.")
            : CheckResult::fail('backups.configured', 'Encrypted off-host backups configured', 'C1', implode('; ', $problems).'.', 'BACKUP_DISK=backups_s3 with BACKUP_S3_* for a versioned bucket (docs/ops/backup-and-restore.md); escrow the archive password.');
    }

    private function backupRecent(): CheckResult
    {
        $catalog = new BackupCatalog(Storage::disk((string) config('backup.disk')), (string) config('backup.prefix'));
        $all = $catalog->all();
        if ($all === []) {
            return CheckResult::fail('backups.recent', 'A backup taken in the last 26 hours', 'C2', 'No complete backup on the backup disk.', 'Run `php artisan toymart:backup` and check the 02:00 Dhaka schedule.');
        }
        $name = (string) array_key_last($all);
        $hours = (int) $all[$name]->diffInHours(CarbonImmutable::now('UTC'), true);

        return $hours <= self::BACKUP_MAX_AGE_HOURS
            ? CheckResult::pass('backups.recent', 'A backup taken in the last 26 hours', 'C2', "Latest backup {$name}, {$hours} h old.")
            : CheckResult::fail('backups.recent', 'A backup taken in the last 26 hours', 'C2', "Latest backup {$name} is {$hours} h old.", 'Check the scheduler and the backup log; run `php artisan toymart:backup` by hand.');
    }

    private function rehearsal(): CheckResult
    {
        $latest = (new RehearsalLog(Storage::disk((string) config('backup.disk'))))->latest();
        if ($latest === null) {
            return CheckResult::fail('backups.rehearsal', 'Restore rehearsal recorded in the last 35 days', 'C3', 'No restore rehearsal has been recorded.', 'Run `php artisan toymart:backup:restore-rehearsal --against=manifest` (it records its result on the backup disk).');
        }
        $days = (int) $latest['rehearsed_at']->diffInDays(CarbonImmutable::now('UTC'), true);
        $what = "{$latest['backup']}, {$latest['checks']} checks against {$latest['against']}, {$days} day(s) ago";

        if (! $latest['passed']) {
            return CheckResult::fail('backups.rehearsal', 'Restore rehearsal recorded in the last 35 days', 'C3', "The latest rehearsal FAILED ({$what}).", 'Read its output, fix the backup, then rehearse again. A backup that does not restore is not a backup.');
        }

        return $days <= self::REHEARSAL_MAX_AGE_DAYS
            ? CheckResult::pass('backups.rehearsal', 'Restore rehearsal recorded in the last 35 days', 'C3', "Passed: {$what}.")
            : CheckResult::fail('backups.rehearsal', 'Restore rehearsal recorded in the last 35 days', 'C3', "Last pass was {$what} — the monthly drill is overdue.", 'Run `php artisan toymart:backup:restore-rehearsal --against=manifest`.');
    }

    // ── D. Integrations ──────────────────────────────────────────────────────────────────────

    private function mail(): CheckResult
    {
        $mailer = (string) config('mail.default');
        $from = strtolower((string) config('mail.from.address'));
        $problems = [];
        if (in_array($mailer, ['log', 'array', 'null'], true)) {
            $problems[] = "MAIL_MAILER={$mailer} sends nothing";
        }
        if ($from === '' || str_ends_with($from, '@example.com') || str_ends_with($from, '.test')) {
            $problems[] = 'MAIL_FROM_ADDRESS is a placeholder';
        }
        if ($mailer === 'smtp' && in_array((string) config('mail.mailers.smtp.host'), ['127.0.0.1', 'localhost', 'mailpit'], true)) {
            $problems[] = 'SMTP host is a local catcher';
        }

        return $problems === []
            ? CheckResult::pass('mail.driver', 'Mail driver configured', 'D1', "MAIL_MAILER={$mailer}, from {$from}.")
            : CheckResult::fail('mail.driver', 'Mail driver configured', 'D1', implode('; ', $problems).'.', 'Configure the transactional mail provider (MAIL_* in the host env) on the shop\'s own domain; then publish SPF/DKIM/DMARC (D11).');
    }

    private function sms(): CheckResult
    {
        /** @var MessagingSettings $settings */
        $settings = $this->app->make(MessagingSettings::class);
        $driver = $settings->smsDriver();
        if ($driver !== 'reve') {
            return CheckResult::fail('sms.driver', 'SMS provider configured', 'D2', "SMS driver is `{$driver}`: OTP and order SMS are not sent.", 'Admin → Integrations → Messaging: choose REVE SMS, enter the API key, secret key and sender ID, then "Send test SMS" in English and Bangla.');
        }
        $missing = array_keys(array_filter($settings->reve(), static fn (mixed $v, string $k): bool => $k !== 'timeout' && ($v === null || $v === ''), ARRAY_FILTER_USE_BOTH));

        return $missing === []
            ? CheckResult::pass('sms.driver', 'SMS provider configured', 'D2', 'REVE SMS selected with base URL, keys and sender ID present (values not shown).')
            : CheckResult::fail('sms.driver', 'SMS provider configured', 'D2', 'REVE SMS is selected but missing: '.implode(', ', $missing).'.', 'Fill them in at Admin → Integrations → Messaging.');
    }

    private function whatsapp(): CheckResult
    {
        /** @var MessagingSettings $settings */
        $settings = $this->app->make(MessagingSettings::class);
        $driver = (string) config('notifications.whatsapp.driver');
        if ($settings->whatsappEnabled() && in_array($driver, ['log', 'null'], true)) {
            return CheckResult::fail('whatsapp.state', 'WhatsApp off unless a real account is connected', 'D3', "WhatsApp is ON with the `{$driver}` driver: order updates \"succeed\" into a log and the SMS fallback never fires — customers get nothing.", 'Turn the WhatsApp switch off at Admin → Integrations → Messaging (and WHATSAPP_ENABLED=false).');
        }

        return $settings->whatsappEnabled()
            ? CheckResult::pass('whatsapp.state', 'WhatsApp off unless a real account is connected', 'D3', "WhatsApp on with the `{$driver}` driver.")
            : CheckResult::warn('whatsapp.state', 'WhatsApp off unless a real account is connected', 'D3', 'WhatsApp is off: order updates go by SMS (2–3× the cost in Bangla, rule 39). Correct while no WhatsApp Business account exists.', 'Owner: open a WhatsApp Business account and get the utility templates approved; until then this stays a warning.');
    }

    private function couriers(): CheckResult
    {
        $active = CourierProvider::query()->where('is_active', true)->orderBy('position')->get();
        $verified = $active->where('verified', true);
        $unverified = $active->where('verified', false);
        $guarded = in_array((string) $this->app->environment(), (array) config('couriers.production_environments', ['production']), true)
            && BookingPreflight::isProduction();

        if ($unverified->isNotEmpty() && ! $guarded) {
            return CheckResult::fail('couriers.verified', 'Courier providers verified; unverified ones blocked', 'D4', 'Unverified provider(s) '.$unverified->pluck('code')->implode(', ').' are active and this environment is NOT in couriers.production_environments, so the rule-33a booking block is off.', 'Run with APP_ENV=production (the block keys on it), or deactivate the unverified provider.');
        }
        if ($verified->isEmpty()) {
            return CheckResult::fail('couriers.verified', 'Courier providers verified; unverified ones blocked', 'D4', 'No active, verified courier provider: no parcel can be booked through the API'.($unverified->isNotEmpty() ? ' (active but unverified and blocked: '.$unverified->pluck('code')->implode(', ').')' : '').'.', 'Owner: open a Pathao or Steadfast merchant account; after the live booking test (D8), run `php artisan toymart:couriers:provider <code> --verify`. Manual "Mark shipped" still works meanwhile.');
        }
        $note = $unverified->isEmpty() ? '' : ' Unverified and blocked from production booking: '.$unverified->pluck('code')->implode(', ').'.';

        return $unverified->isEmpty()
            ? CheckResult::pass('couriers.verified', 'Courier providers verified; unverified ones blocked', 'D4', 'Verified: '.$verified->pluck('code')->implode(', ').'.')
            : CheckResult::warn('couriers.verified', 'Courier providers verified; unverified ones blocked', 'D4', 'Verified: '.$verified->pluck('code')->implode(', ').'.'.$note, 'Deactivate the unverified provider so staff are not offered it, or verify it against its live API.');
    }

    private function rateCards(): CheckResult
    {
        $stale = CourierProvider::query()->where('is_active', true)->whereNull('rate_card_updated_at')->pluck('code')->all();

        return $stale === []
            ? CheckResult::pass('couriers.rate_card', 'Courier rate cards entered by the owner', 'D5', 'Every active provider\'s rate card was saved in the admin.')
            : CheckResult::fail('couriers.rate_card', 'Courier rate cards entered by the owner', 'D5', 'Still the seeded placeholder rates (৳70/৳100/৳130): '.implode(', ', $stale).'. They price the customer\'s delivery charge.', 'Admin → Integrations → Couriers → Rate card: enter the contracted rates and save (even if they match).');
    }

    private function payments(): CheckResult
    {
        /** @var OnlineGateways $gateways */
        $gateways = $this->app->make(OnlineGateways::class);
        $enabled = [];
        $broken = [];
        foreach (PaymentMethod::cases() as $method) {
            if (! $method->isOnline()) {
                continue;
            }
            $status = $gateways->status($method);
            if ($status['enabled']) {
                $enabled[] = $method->value;
            } elseif ($status['active']) {
                $broken[] = $method->value.' ('.$status['reason'].')';
            }
        }

        if ($broken !== []) {
            return CheckResult::fail('payments.gateway', 'Online payment gateway state', 'D6', 'Active but not taking payments: '.implode(', ', $broken).'.', 'Either finish it (live credentials, `php artisan toymart:payments:gateway <code> --verify --live` after the live test D7) or deactivate it.');
        }

        return $enabled !== []
            ? CheckResult::pass('payments.gateway', 'Online payment gateway state', 'D6', 'Taking online payments: '.implode(', ', $enabled).' (verified, live). COD is always on.')
            : CheckResult::warn('payments.gateway', 'Online payment gateway state', 'D6', 'No online gateway is active: the shop launches COD-only.', 'Owner decision (Gate 1 decision 5): launch COD-only, or finish SSLCommerz merchant onboarding first.');
    }

    // ── E. SEO ───────────────────────────────────────────────────────────────────────────────

    private function robots(): CheckResult
    {
        $blocked = array_values(array_filter(['/', '/toys', '/bn', '/products/x', '/categories/x'], static fn (string $p): bool => ! RobotsTxt::allows($p)));
        $sitemap = RobotsTxt::sitemapUrl();
        $problems = [];
        if ($blocked !== []) {
            $problems[] = 'robots.txt blocks '.implode(', ', $blocked);
        }
        if (! str_starts_with($sitemap, 'https://')) {
            $problems[] = "the Sitemap line points to {$sitemap}";
        }

        return $problems === []
            ? CheckResult::pass('seo.robots', 'robots.txt allows crawling', 'E1', "Shop pages are crawlable; Sitemap: {$sitemap}.")
            : CheckResult::fail('seo.robots', 'robots.txt allows crawling', 'E1', implode('; ', $problems).'.', 'Admin → SEO → Sitemap & robots: restore the default, then check https://<shop>/robots.txt.');
    }

    private function sitemap(): CheckResult
    {
        $manifest = SitemapBuilder::manifest();
        if ($manifest === null || SitemapBuilder::read('sitemap.xml') === null) {
            return CheckResult::fail('seo.sitemap', 'Sitemap generated', 'E2', 'No sitemap has been generated on this server.', 'Admin → SEO → Sitemap & robots → Rebuild (or wait for the 03:00 Dhaka job); then open https://<shop>/sitemap.xml.');
        }
        $hours = (int) CarbonImmutable::parse($manifest['generated_at'])->diffInHours(CarbonImmutable::now(), true);
        $urls = $manifest['total_urls'];
        if ($urls === 0) {
            return CheckResult::fail('seo.sitemap', 'Sitemap generated', 'E2', 'The sitemap lists no URLs.', 'Publish products and pages, then rebuild.');
        }
        if (! str_starts_with(\App\Domain\Seo\Services\SiteUrl::base(), 'https://')) {
            return CheckResult::fail('seo.sitemap', 'Sitemap generated', 'E2', 'The sitemap URLs are not https (STOREFRONT_URL).', 'Set STOREFRONT_URL to the https shop origin and rebuild.');
        }

        return $hours <= self::SITEMAP_MAX_AGE_HOURS
            ? CheckResult::pass('seo.sitemap', 'Sitemap generated', 'E2', "{$urls} URLs in ".count($manifest['files'])." file(s), built {$hours} h ago.")
            : CheckResult::fail('seo.sitemap', 'Sitemap generated', 'E2', "The sitemap is {$hours} h old: the nightly rebuild is not running.", 'Check the scheduler (B3) and the `index` queue; rebuild from the admin.');
    }

    // ── F. Content, legal and business values ────────────────────────────────────────────────

    private function policyPages(): CheckResult
    {
        $pages = CmsPage::query()->whereIn('slug', self::POLICY_PAGES)->get()->keyBy('slug');
        $problems = [];
        foreach (self::POLICY_PAGES as $slug) {
            /** @var CmsPage|null $page */
            $page = $pages->get($slug);
            if ($page === null) {
                $problems[] = "{$slug}: missing";

                continue;
            }
            if ($page->status !== PageStatus::Published || ! $page->isLive()) {
                $problems[] = "{$slug}: not published";

                continue;
            }
            $faq = is_array($page->faq_items) ? $page->faq_items : [];
            $en = trim(strip_tags((string) $page->content)).' '.implode(' ', array_map(static fn (array $i): string => $i['question'].' '.$i['answer'], $faq));
            $bn = trim(strip_tags((string) $page->content_bn)).' '.implode(' ', array_map(static fn (array $i): string => ($i['question_bn'] ?? '').' '.($i['answer_bn'] ?? ''), $faq));
            if (trim($en) === '' || trim($bn) === '' || trim((string) $page->title_bn) === '') {
                $problems[] = "{$slug}: English or Bangla text missing";

                continue;
            }
            $marker = self::firstMarker($en.' '.$bn.' '.$page->title.' '.$page->title_bn);
            if ($marker !== null) {
                $problems[] = "{$slug}: contains \"{$marker}\"";
            }
        }

        return $problems === []
            ? CheckResult::pass('content.policy_pages', 'Policy pages published with real details', 'F1', 'Published in English and Bangla with no placeholder text: '.implode(', ', self::POLICY_PAGES).'.')
            : CheckResult::fail('content.policy_pages', 'Policy pages published with real details', 'F1', implode('; ', $problems).'.', 'Owner: write each page in English and Bangla at Admin → Store → Pages (/admin/store/pages) and publish it. Legal text is the owner\'s (and a lawyer\'s), not ours.');
    }

    private function companyDetails(): CheckResult
    {
        $placeholders = (new CompanyDetails)->snapshot()['placeholders'];
        $saved = (new CompanyDetails)->saved();
        $fake = array_keys(array_filter($saved, static fn (mixed $v): bool => is_string($v) && self::firstMarker($v) !== null));
        $missing = [...(is_array($placeholders) ? $placeholders : []), ...$fake];

        return $missing === []
            ? CheckResult::pass('business.company_details', 'Company, trade licence and BIN on invoices', 'F2', 'Name, address (EN/BN), hotline, trade licence and BIN are set for invoices.')
            : CheckResult::fail('business.company_details', 'Company, trade licence and BIN on invoices', 'F2', 'Not set (invoices print "[placeholder]"): '.implode(', ', array_unique($missing)).'.', 'Admin → Settings → Invoice details. Invoices snapshot these at issue, so set them before the first real invoice.');
    }

    private function hotline(): CheckResult
    {
        $hotline = preg_replace('/\D+/', '', (string) config('notifications.hotline')) ?? '';
        $domain = (string) config('notifications.store_url');
        $problems = [];
        if ($hotline === '' || in_array($hotline, self::PLACEHOLDER_HOTLINES, true)) {
            $problems[] = 'STORE_HOTLINE is the placeholder 09600123456 (it is printed in SMS and emails)';
        }
        if ($domain === '' || str_contains($domain, 'localhost') || str_ends_with($domain, '.test')) {
            $problems[] = "STORE_PUBLIC_DOMAIN is `{$domain}`";
        }

        return $problems === []
            ? CheckResult::pass('business.hotline', 'Store hotline and domain in messages', 'F3', "Hotline set; domain {$domain}.")
            : CheckResult::fail('business.hotline', 'Store hotline and domain in messages', 'F3', implode('; ', $problems).'.', 'Set STORE_HOTLINE and STORE_PUBLIC_DOMAIN in the host env, `php artisan config:cache`, then `php artisan toymart:notifications:sync-templates`.');
    }

    private function storefrontSiteConfig(): CheckResult
    {
        $path = $this->app->basePath(self::STOREFRONT_SITE_CONFIG);
        if (! is_file($path)) {
            return CheckResult::manual('storefront.site_config', 'Storefront contact and legal details are real', 'F4', 'The storefront source is not on this host. On the storefront build, confirm toymartbd-app/storefront/lib/site-config.ts has no `placeholder: true`, and tradeLicence/bin are set.');
        }
        $source = (string) file_get_contents($path);
        $flags = preg_match_all('/placeholder:\s*true/', $source);
        $problems = [];
        if ($flags > 0) {
            $problems[] = "{$flags} value(s) still marked `placeholder: true` (hotline, WhatsApp, email, social, logo, legal name)";
        }
        if (preg_match('/tradeLicence:\s*null/', $source) === 1 || preg_match('/\bbin:\s*null/', $source) === 1) {
            $problems[] = 'trade licence / BIN not set for the footer legal bar';
        }
        if (preg_match('/addressIsPlaceholder\s*=\s*true/', $source) === 1) {
            $problems[] = 'street address is a placeholder';
        }

        return $problems === []
            ? CheckResult::pass('storefront.site_config', 'Storefront contact and legal details are real', 'F4', 'No placeholder contact or legal values in the storefront config.')
            : CheckResult::fail('storefront.site_config', 'Storefront contact and legal details are real', 'F4', implode('; ', $problems).'.', 'Replace the values in toymartbd-app/storefront/lib/site-config.ts (and the footer copy in both message catalogues) with the owner\'s, set `placeholder: false`, redeploy the storefront.');
    }

    private function placeholderValues(): CheckResult
    {
        $unconfirmed = [];
        $threshold = Setting::query()->find(DeliverySettings::PREFIX.'free_shipping_threshold');
        if (! $threshold instanceof Setting || $threshold->updated_by === null) {
            $unconfirmed[] = 'free-delivery threshold (seeded ৳3,000)';
        }
        $risk = Setting::query()->find(RiskSettings::KEY);
        if (! $risk instanceof Setting || $risk->updated_by === null) {
            $unconfirmed[] = 'risk rules incl. first-order hold ৳'.config('risk.first_order_hold_threshold').' (placeholder)';
        }
        // The refund approval threshold (৳2,000) is an owner decision of 2026-11-12/13, not a placeholder.
        if ((int) config('returns.window_days') === 7) {
            $unconfirmed[] = 'return window 7 days (7 vs 10 is a legal question)';
        }

        return $unconfirmed === []
            ? CheckResult::pass('business.placeholder_values', 'Placeholder business values confirmed', 'F5', 'Delivery threshold and risk rules were saved by a person; the return window was set.')
            : CheckResult::warn('business.placeholder_values', 'Placeholder business values confirmed', 'F5', 'Still at the design/seed default (UI-UX-DESIGN-SPEC §10): '.implode('; ', $unconfirmed).'.', 'Owner confirms or changes each (Admin → Integrations → Couriers for the free-delivery threshold, Admin → Risk → Rules; RETURN_WINDOW_DAYS in the env) and records the decision in GATE-5-REVIEW.md.');
    }

    // ── G. People and data ───────────────────────────────────────────────────────────────────

    private function superAdmin(): CheckResult
    {
        $owners = $this->staff()->whereHas('roles', static fn ($q) => $q->where('name', Role::SuperAdmin->value))->count();

        return $owners >= 1
            ? CheckResult::pass('staff.super_admin', 'At least one super admin', 'G1', "{$owners} active super admin account(s).")
            : CheckResult::fail('staff.super_admin', 'At least one super admin', 'G1', 'No active super admin: nobody can manage staff or roles.', 'Create the owner\'s account (docs/ops/RUNBOOK.md "Add or remove a staff member").');
    }

    private function staffRoles(): CheckResult
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, User> $staff */
        $staff = $this->staff()->with('roles')->get();
        $roleless = $staff->filter(static fn (User $u): bool => $u->roles->isEmpty())->count();
        $others = $staff->filter(static fn (User $u): bool => $u->roles->isNotEmpty() && ! $u->hasRole(Role::SuperAdmin->value))->count();

        if ($roleless > 0) {
            return CheckResult::fail('staff.roles', 'Staff accounts have roles', 'G2', "{$roleless} active staff account(s) have no role (they can sign in and see nothing).", 'Give each a role or deactivate it (docs/ops/RUNBOOK.md "Add or remove a staff member" — there is no staff screen yet).');
        }

        return $others >= 1
            ? CheckResult::pass('staff.roles', 'Staff accounts have roles', 'G2', "{$others} staff account(s) besides the super admin(s), each with a role.")
            : CheckResult::warn('staff.roles', 'Staff accounts have roles', 'G2', 'Only super admin accounts exist: day-to-day staff would work with full rights.', 'Create staff accounts with the narrowest role that fits (docs/ops/RUNBOOK.md "Add or remove a staff member").');
    }

    private function noDemoData(): CheckResult
    {
        $found = [];
        $users = User::query()->where('email', 'like', '%@toymart.test')->count();
        if ($users > 0) {
            $found[] = "{$users} demo login(s) (@toymart.test, password \"password\")";
        }
        $phones = array_values(array_filter(array_map(
            static fn (string $p): ?string => PhoneNumber::tryParse($p)?->e164(),
            DemoCustomersSeeder::PHONES,
        )));
        $customers = Customer::query()->whereIn('phone', $phones)->count();
        if ($customers > 0) {
            $found[] = "{$customers} demo customer(s)";
        }
        $products = Product::query()->whereIn('slug', array_keys(DemoCatalogSeeder::PRODUCTS))->count();
        if ($products > 0) {
            $found[] = "{$products} demo product(s)";
        }

        return $found === []
            ? CheckResult::pass('data.no_demo', 'No demo or seed data', 'G3', 'No demo logins, customers or products.')
            : CheckResult::fail('data.no_demo', 'No demo or seed data', 'G3', 'Found '.implode(', ', $found).'.', 'Production must start from `php artisan db:seed --force` with APP_ENV=production (reference data only). Remove the demo rows — a demo login is a back door.');
    }

    // ── H. Monitoring ────────────────────────────────────────────────────────────────────────

    private function sentry(): CheckResult
    {
        $dsn = (string) config('sentry.dsn');
        $release = (string) config('sentry.release');

        if ($dsn === '') {
            return CheckResult::fail('monitoring.sentry', 'Sentry configured with the release SHA', 'H1', 'SENTRY_LARAVEL_DSN is empty: production errors are only in the log file.', 'Create the Sentry project, set SENTRY_LARAVEL_DSN and SENTRY_RELEASE=<git sha> (the deploy sets the release), see docs/ops/observability.md.');
        }

        return $release !== ''
            ? CheckResult::pass('monitoring.sentry', 'Sentry configured with the release SHA', 'H1', 'Sentry DSN set (not shown), release '.substr($release, 0, 12).'.')
            : CheckResult::warn('monitoring.sentry', 'Sentry configured with the release SHA', 'H1', 'Sentry DSN set, but no SENTRY_RELEASE: errors cannot be tied to a deploy.', 'Have the deploy export SENTRY_RELEASE=<git sha>.');
    }

    private function logs(): CheckResult
    {
        $stack = (array) config('logging.channels.stack.channels', []);
        $level = (string) config('logging.channels.json.level', config('logging.level', 'debug'));
        $json = in_array('json', $stack, true);

        return $json
            ? CheckResult::pass('monitoring.logs', 'JSON logs for the log shipper', 'H2', 'LOG_STACK includes json.')
            : CheckResult::warn('monitoring.logs', 'JSON logs for the log shipper', 'H2', 'LOG_STACK is `'.implode(',', $stack)."` (level {$level}).", 'Set LOG_STACK=json and LOG_LEVEL=info so the host\'s log shipper gets one JSON object per line (docs/ops/observability.md).');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /**
     * Active staff accounts — never the storefront service account.
     *
     * @return Builder<User>
     */
    private function staff(): Builder
    {
        return User::query()->where('is_active', true)->where('email', '!=', IssueStorefrontToken::SERVICE_EMAIL);
    }

    public static function publicHttpsProblem(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return 'is not set';
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return 'is not https';
        }
        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.test') || str_ends_with($host, '.local') || str_ends_with($host, 'example.com') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return "has a non-public host ({$host})";
        }

        return null;
    }

    public static function firstMarker(string $text): ?string
    {
        $lower = mb_strtolower($text);
        foreach (self::PLACEHOLDER_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return $marker;
            }
        }

        return null;
    }

    /** Exception text can carry a DSN or a host:password; keep it out of the report. */
    private static function scrub(string $message): string
    {
        return (string) preg_replace(['#://[^@\s/]+@#', '/password=\S+/i'], ['://[redacted]@', 'password=[redacted]'], mb_substr($message, 0, 300));
    }
}

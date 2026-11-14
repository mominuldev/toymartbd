<?php

declare(strict_types=1);

use App\Console\Commands\Backup\RehearsalLog;
use App\Console\Commands\Launch\LaunchChecks;
use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Customer\Models\Customer;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Services\DeliverySettings;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Notification\Services\MessagingSettings;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Risk\Services\RiskSettings;
use App\Domain\Seo\Services\RobotsTxt;
use App\Domain\Seo\Services\SitemapBuilder;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\SystemHeartbeat;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StaticPagesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/*
| P10 launch readiness: `php artisan toymart:launch:check` (docs/ops/LAUNCH-CHECKLIST.md).
| Every automated check gets a passing and a failing case. The command is read-only; the
| tests also prove it never prints a secret value.
*/

/** @return array{ready: bool, summary: array<string, int>, checks: list<array{id: string, status: string, detail: string, fix: string|null, checklist: string}>} */
function launchReport(string ...$only): array
{
    $args = ['--json' => true];
    if ($only !== []) {
        $args['--only'] = $only;
    }
    Artisan::call('toymart:launch:check', $args);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

function launchStatus(string $id): string
{
    $check = collect(launchReport($id)['checks'])->firstWhere('id', $id);
    expect($check)->not->toBeNull();

    return $check['status'];
}

function launchDetail(string $id): string
{
    return (string) collect(launchReport($id)['checks'])->firstWhere('id', $id)['detail'];
}

/** Seeders confirm before running in production; seed as `testing`, then switch back. */
function seedOutsideProduction(string $seeder): void
{
    app()->detectEnvironment(fn (): string => 'testing');
    test()->seed($seeder);
    app()->detectEnvironment(fn (): string => 'production');
}

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()->detectEnvironment(fn (): string => 'production');
});

// ── command contract ─────────────────────────────────────────────────────────────────────────

it('exits non-zero and reports ready=false when any check fails', function (): void {
    config(['app.url' => 'http://localhost']);

    expect(Artisan::call('toymart:launch:check', ['--json' => true, '--only' => ['env.app_url']]))->toBe(1)
        ->and(json_decode(Artisan::output(), true)['ready'])->toBeFalse();
});

it('exits zero when every selected check passes, and fails on a warning only with --strict', function (): void {
    expect(Artisan::call('toymart:launch:check', ['--json' => true, '--only' => ['env.app_env']]))->toBe(0);

    // No online gateway active → WARN (COD-only launch).
    expect(Artisan::call('toymart:launch:check', ['--only' => ['payments.gateway']]))->toBe(0)
        ->and(Artisan::call('toymart:launch:check', ['--only' => ['payments.gateway'], '--strict' => true]))->toBe(1);
});

it('lists the manual items with a pointer to the checklist, and can leave them out', function (): void {
    $manual = collect(launchReport()['checks'])->where('status', 'manual');

    expect($manual->pluck('id')->all())->toContain('live.payment', 'live.courier', 'deploy.rollback', 'env.https_forced', 'seo.submitted', 'analytics.dedup', 'owner.runbook')
        ->and($manual->every(fn (array $c): bool => $c['checklist'] !== '' && $c['detail'] !== ''))->toBeTrue();

    Artisan::call('toymart:launch:check', ['--json' => true, '--no-manual' => true]);
    expect(collect(json_decode(Artisan::output(), true)['checks'])->where('status', 'manual'))->toBeEmpty();
});

it('every check id maps to an item in docs/ops/LAUNCH-CHECKLIST.md', function (): void {
    $doc = (string) file_get_contents(base_path('docs/ops/LAUNCH-CHECKLIST.md'));

    foreach (array_keys(app(LaunchChecks::class)->checks()) as $id) {
        expect($doc)->toContain('`'.$id.'`');
    }
});

it('never prints a secret value', function (): void {
    $secrets = ['db-pass-Zq81xx', 'backup-pass-0123456789abcdef', str_repeat('r', 40), 'meili-key-9988'];
    config([
        'database.connections.sqlite.password' => $secrets[0],
        'backup.archive_password' => $secrets[1],
        'services.storefront.revalidate_secret' => $secrets[2],
        'scout.driver' => 'meilisearch',
        'scout.meilisearch.key' => $secrets[3],
        'sentry.dsn' => 'https://publickey@o1.ingest.sentry.io/2',
    ]);

    Artisan::call('toymart:launch:check', ['--json' => true]);
    $json = Artisan::output();
    Artisan::call('toymart:launch:check');
    $text = Artisan::output();

    foreach ([...$secrets, 'publickey'] as $secret) {
        expect($json)->not->toContain($secret)->and($text)->not->toContain($secret);
    }
});

it('turns an exception inside one check into a FAIL for that check only', function (): void {
    app()->bind(MasterSupervisorRepository::class, fn () => throw new RuntimeException('redis://user:hunter2@10.0.0.5 refused'));

    $report = launchReport('queue.horizon', 'env.app_env');
    $horizon = collect($report['checks'])->firstWhere('id', 'queue.horizon');

    expect($horizon['status'])->toBe('fail')
        ->and($horizon['detail'])->not->toContain('hunter2')
        ->and(collect($report['checks'])->firstWhere('id', 'env.app_env')['status'])->toBe('pass');
});

// ── A. environment and security ──────────────────────────────────────────────────────────────

it('checks APP_ENV', function (): void {
    expect(launchStatus('env.app_env'))->toBe('pass');

    app()->detectEnvironment(fn (): string => 'staging');
    expect(launchStatus('env.app_env'))->toBe('fail');
});

it('checks APP_DEBUG', function (): void {
    config(['app.debug' => false]);
    expect(launchStatus('env.app_debug'))->toBe('pass');

    config(['app.debug' => true]);
    expect(launchStatus('env.app_debug'))->toBe('fail');
});

it('requires public https APP_URL and STOREFRONT_URL', function (string $app, string $shop, string $status): void {
    config(['app.url' => $app, 'services.storefront.url' => $shop]);

    expect(launchStatus('env.app_url'))->toBe($status);
})->with([
    'both public https' => ['https://api.toymartbd.com', 'https://toymartbd.com', 'pass'],
    'http api' => ['http://api.toymartbd.com', 'https://toymartbd.com', 'fail'],
    'localhost shop' => ['https://api.toymartbd.com', 'https://localhost:3000', 'fail'],
    'unset shop' => ['https://api.toymartbd.com', '', 'fail'],
    'ip address' => ['https://10.0.0.5', 'https://toymartbd.com', 'fail'],
]);

it('checks the session cookie flags and store', function (): void {
    config(['session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax', 'session.driver' => 'redis']);
    expect(launchStatus('security.session_cookie'))->toBe('pass');

    config(['session.secure' => false]);
    expect(launchStatus('security.session_cookie'))->toBe('fail');
});

it('checks APP_KEY is set', function (): void {
    expect(launchStatus('security.app_key'))->toBe('pass');

    config(['app.key' => '']);
    expect(launchStatus('security.app_key'))->toBe('fail');
});

it('requires the secrets, refusing the .env.example placeholders', function (): void {
    $good = [
        'database.connections.sqlite.password' => 'a-real-db-password',
        'database.redis.default.password' => 'a-real-redis-password',
        'backup.archive_password' => str_repeat('b', 32),
        'services.storefront.revalidate_secret' => str_repeat('c', 64),
        'scout.driver' => 'meilisearch',
        'scout.meilisearch.key' => 'a-real-master-key',
    ];
    config($good);
    expect(launchStatus('secrets.required'))->toBe('pass');

    config(['scout.meilisearch.key' => 'local-master-key-change-me']);
    expect(launchStatus('secrets.required'))->toBe('fail')
        ->and(launchDetail('secrets.required'))->toContain('MEILISEARCH_KEY');

    config($good + ['backup.archive_password' => 'short']);
    expect(launchDetail('secrets.required'))->toContain('BACKUP_ARCHIVE_PASSWORD');
});

it('fails a world-readable .env and passes a private one', function (): void {
    $dir = sys_get_temp_dir().'/launch-env-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/.env', 'APP_ENV=production');
    app()->useEnvironmentPath($dir);

    chmod($dir.'/.env', 0640);
    expect(launchStatus('secrets.env_file'))->toBe('pass');

    chmod($dir.'/.env', 0644);
    expect(launchStatus('secrets.env_file'))->toBe('fail');

    unlink($dir.'/.env');
    rmdir($dir);
});

it('requires TRUSTED_PROXIES', function (): void {
    config(['trustedproxy.proxies' => ['10.0.0.2']]);
    expect(launchStatus('security.trusted_proxies'))->toBe('pass');

    config(['trustedproxy.proxies' => []]);
    expect(launchStatus('security.trusted_proxies'))->toBe('fail');
});

it('warns when config and routes are not cached', function (): void {
    expect(launchStatus('app.optimized'))->toBe('warn');
});

it('fails anything but MySQL with the Bangla-safe collation', function (): void {
    if (onlyOnMysql()) {
        expect(launchStatus('database.connection'))->toBe('fail'); // the SQLite suite

        return;
    }
    expect(launchStatus('database.connection'))->toBe('pass');
});

// ── B. runtime ───────────────────────────────────────────────────────────────────────────────

it('requires Redis queues and cache', function (): void {
    config(['queue.default' => 'redis', 'cache.default' => 'redis']);
    expect(launchStatus('queue.connection'))->toBe('pass');

    config(['queue.default' => 'database']);
    expect(launchStatus('queue.connection'))->toBe('fail');
});

it('requires a running Horizon', function (array $masters, string $status): void {
    $repo = Mockery::mock(MasterSupervisorRepository::class);
    $repo->shouldReceive('all')->andReturn($masters);
    app()->instance(MasterSupervisorRepository::class, $repo);

    expect(launchStatus('queue.horizon'))->toBe($status);
})->with([
    'running' => [[(object) ['name' => 'host-1', 'status' => 'running']], 'pass'],
    'none' => [[], 'fail'],
    'paused' => [[(object) ['name' => 'host-1', 'status' => 'paused']], 'fail'],
]);

it('reads the scheduler heartbeat owned by monitoring', function (): void {
    expect(launchStatus('schedule.heartbeat'))->toBe('fail');

    SystemHeartbeat::beat(SystemHeartbeat::SCHEDULER);
    expect(launchStatus('schedule.heartbeat'))->toBe('pass');

    $this->travel(20)->minutes();
    expect(launchStatus('schedule.heartbeat'))->toBe('fail');
});

it('requires Meilisearch', function (): void {
    config(['scout.driver' => 'meilisearch']);
    expect(launchStatus('search.driver'))->toBe('pass');

    config(['scout.driver' => 'null']);
    expect(launchStatus('search.driver'))->toBe('fail');
});

it('requires exactly one live storefront token', function (): void {
    expect(launchStatus('storefront.token'))->toBe('fail');

    Artisan::call('toymart:storefront-token');
    expect(launchStatus('storefront.token'))->toBe('pass');

    Artisan::call('toymart:storefront-token');
    expect(launchStatus('storefront.token'))->toBe('warn');

    Artisan::call('toymart:storefront-token', ['--rotate' => true]);
    expect(launchStatus('storefront.token'))->toBe('pass');
});

it('warns while the API is in maintenance mode', function (): void {
    expect(launchStatus('app.maintenance'))->toBe('pass');

    app()->maintenanceMode()->activate([]);
    expect(launchStatus('app.maintenance'))->toBe('warn');
    app()->maintenanceMode()->deactivate();
});

// ── C. backups ───────────────────────────────────────────────────────────────────────────────

it('requires an off-host, encrypted backup disk', function (): void {
    config(['backup.disk' => 'backups_s3', 'filesystems.disks.backups_s3.driver' => 's3', 'backup.archive_password' => str_repeat('x', 32)]);
    expect(launchStatus('backups.configured'))->toBe('pass');

    config(['backup.disk' => 'backups']);
    expect(launchStatus('backups.configured'))->toBe('fail');
});

it('requires a backup from the last 26 hours', function (): void {
    Storage::fake('backups');
    config(['backup.disk' => 'backups']);
    expect(launchStatus('backups.recent'))->toBe('fail');

    $disk = Storage::disk('backups');
    $name = 'toymartbd-'.CarbonImmutable::now('UTC')->subHours(3)->format('Ymd-His');
    $disk->put($name.'.tar.gz.enc', 'x');
    $disk->put($name.'.manifest.json', '{}');
    expect(launchStatus('backups.recent'))->toBe('pass');

    $this->travel(2)->days();
    expect(launchStatus('backups.recent'))->toBe('fail');
});

it('requires a recorded, passing restore rehearsal from the last 35 days', function (): void {
    Storage::fake('backups');
    config(['backup.disk' => 'backups']);
    $log = new RehearsalLog(Storage::disk('backups'));
    expect(launchStatus('backups.rehearsal'))->toBe('fail');

    $log->record('toymartbd-20261110-200000', true, 'manifest', 32, CarbonImmutable::now('UTC')->subDays(3));
    expect(launchStatus('backups.rehearsal'))->toBe('pass');

    $log->record('toymartbd-20261111-200000', false, 'manifest', 32, CarbonImmutable::now('UTC')->subDay());
    expect(launchStatus('backups.rehearsal'))->toBe('fail')
        ->and(launchDetail('backups.rehearsal'))->toContain('FAILED');

    $log->record('toymartbd-20261111-200000', true, 'manifest', 32, CarbonImmutable::now('UTC')->subDays(40)->addSecond());
    // An older pass does not outrank the newer failure.
    expect(launchStatus('backups.rehearsal'))->toBe('fail');
});

it('records the rehearsal outcome on the backup disk when the rehearsal runs', function (): void {
    Storage::fake('backups');
    $log = new RehearsalLog(Storage::disk('backups'));
    $log->record('toymartbd-20261110-200000', true, 'source', 32);

    expect($log->latest())->toMatchArray(['backup' => 'toymartbd-20261110-200000', 'passed' => true, 'against' => 'source', 'checks' => 32])
        ->and(Storage::disk('backups')->files(RehearsalLog::DIR))->toHaveCount(1);
});

// ── D. integrations ──────────────────────────────────────────────────────────────────────────

it('requires a real mail transport and sender', function (): void {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.provider.example', 'mail.from.address' => 'orders@toymartbd.com']);
    expect(launchStatus('mail.driver'))->toBe('pass');

    config(['mail.default' => 'log']);
    expect(launchStatus('mail.driver'))->toBe('fail');

    config(['mail.default' => 'smtp', 'mail.from.address' => 'hello@example.com']);
    expect(launchStatus('mail.driver'))->toBe('fail');
});

it('requires the REVE SMS provider with every credential', function (): void {
    expect(launchStatus('sms.driver'))->toBe('fail');

    Setting::put(MessagingSettings::SMS_DRIVER, 'reve', SettingGroup::Notification);
    expect(launchStatus('sms.driver'))->toBe('fail')->and(launchDetail('sms.driver'))->toContain('api_key');

    Setting::put(MessagingSettings::REVE_API_KEY, 'k-123456789012', SettingGroup::Notification, encrypted: true);
    Setting::put(MessagingSettings::REVE_SECRET_KEY, 's-123456789012', SettingGroup::Notification, encrypted: true);
    Setting::put(MessagingSettings::REVE_SENDER_ID, '8801700000000', SettingGroup::Notification);
    expect(launchStatus('sms.driver'))->toBe('pass')
        ->and(launchDetail('sms.driver'))->not->toContain('k-123456789012');
});

it('fails WhatsApp switched on with the log driver, warns while it is off', function (): void {
    config(['notifications.whatsapp.driver' => 'log']);
    Setting::put(MessagingSettings::WHATSAPP_ENABLED, true, SettingGroup::Notification);
    expect(launchStatus('whatsapp.state'))->toBe('fail');

    Setting::put(MessagingSettings::WHATSAPP_ENABLED, false, SettingGroup::Notification);
    expect(launchStatus('whatsapp.state'))->toBe('warn');
});

it('requires a verified courier, and confirms an unverified Steadfast is blocked from production booking', function (): void {
    seedOutsideProduction(DeliverySeeder::class); // Steadfast active + unverified, Pathao inactive (rule 33a)

    expect(launchStatus('couriers.verified'))->toBe('fail')
        ->and(launchDetail('couriers.verified'))->toContain('blocked: steadfast');

    // The block keys on the environment: outside couriers.production_environments it is off.
    config(['couriers.production_environments' => ['live']]);
    expect(launchDetail('couriers.verified'))->toContain('booking block is off');
    config(['couriers.production_environments' => ['production']]);

    CourierProvider::query()->where('code', 'pathao')->update(['is_active' => true, 'verified' => true]);
    expect(launchStatus('couriers.verified'))->toBe('warn'); // Pathao verified, Steadfast still active + unverified

    CourierProvider::query()->where('code', 'steadfast')->update(['is_active' => false]);
    expect(launchStatus('couriers.verified'))->toBe('pass');
});

it('requires the owner to have saved each active rate card', function (): void {
    seedOutsideProduction(DeliverySeeder::class);
    expect(launchStatus('couriers.rate_card'))->toBe('fail');

    CourierProvider::query()->where('is_active', true)->update(['rate_card_updated_at' => now()]);
    expect(launchStatus('couriers.rate_card'))->toBe('pass');
});

it('fails a gateway that is active but cannot take money, warns on COD-only', function (): void {
    expect(launchStatus('payments.gateway'))->toBe('warn');

    PaymentProvider::query()->updateOrCreate(['code' => 'sslcommerz'], ['name' => 'SSLCommerz', 'is_active' => true, 'verified' => false, 'sandbox' => true]);
    expect(launchStatus('payments.gateway'))->toBe('fail')
        ->and(launchDetail('payments.gateway'))->toContain('sslcommerz');
});

// ── E. SEO ───────────────────────────────────────────────────────────────────────────────────

it('requires robots.txt to allow the shop and point to an https sitemap', function (): void {
    config(['services.storefront.url' => 'https://toymartbd.com']);
    expect(launchStatus('seo.robots'))->toBe('pass');

    Setting::put(RobotsTxt::SETTING, "User-agent: *\nDisallow: /\n", SettingGroup::Seo);
    expect(launchStatus('seo.robots'))->toBe('fail');

    Setting::query()->whereKey(RobotsTxt::SETTING)->delete();
    config(['services.storefront.url' => 'http://localhost:3000']);
    expect(launchStatus('seo.robots'))->toBe('fail');
});

it('requires a fresh, non-empty https sitemap', function (): void {
    Storage::fake('local');
    config(['services.storefront.url' => 'https://toymartbd.com']);
    expect(launchStatus('seo.sitemap'))->toBe('fail');

    app(SitemapBuilder::class)->build();
    expect(launchStatus('seo.sitemap'))->toBe('pass');

    $this->travel(3)->days();
    expect(launchStatus('seo.sitemap'))->toBe('fail');
});

// ── F. content, legal, business values ───────────────────────────────────────────────────────

it('requires every policy page published in both languages without placeholder text', function (): void {
    seedOutsideProduction(StaticPagesSeeder::class);
    expect(launchStatus('content.policy_pages'))->toBe('fail')
        ->and(launchDetail('content.policy_pages'))->toContain('privacy-policy: not published');

    CmsPage::query()->update(['status' => PageStatus::Published, 'published_at' => now()->subMinute()]);
    expect(launchDetail('content.policy_pages'))->toContain('contains "placeholder"');

    CmsPage::query()->update(['content' => '<p>We keep your phone number only to deliver your order.</p>', 'content_bn' => '<p>শুধু অর্ডার পৌঁছাতে আপনার ফোন নম্বর রাখি।</p>']);
    expect(launchStatus('content.policy_pages'))->toBe('pass');

    CmsPage::query()->where('slug', 'terms')->update(['content_bn' => '']);
    expect(launchDetail('content.policy_pages'))->toContain('terms: English or Bangla text missing');
});

it('requires the invoice company details, trade licence and BIN', function (): void {
    expect(launchStatus('business.company_details'))->toBe('fail')
        ->and(launchDetail('business.company_details'))->toContain('trade_licence');

    foreach (['name_en' => 'Toy Mart BD Ltd', 'name_bn' => 'টয় মার্ট বিডি লিমিটেড', 'address_en' => 'House 1, Road 2, Dhanmondi, Dhaka', 'address_bn' => 'বাড়ি 1, রোড 2, ধানমন্ডি, ঢাকা', 'hotline' => '09612000111', 'trade_licence' => 'TRAD/DNCC/001234/2026', 'bin' => '001234567-0101'] as $field => $value) {
        Setting::put(CompanyDetails::PREFIX.$field, $value, SettingGroup::Store);
    }
    expect(launchStatus('business.company_details'))->toBe('pass');

    Setting::put(CompanyDetails::PREFIX.'bin', 'PLACEHOLDER', SettingGroup::Store);
    expect(launchStatus('business.company_details'))->toBe('fail');
});

it('rejects the placeholder hotline and a local store domain', function (): void {
    config(['notifications.hotline' => '09600123456', 'notifications.store_url' => 'toymartbd.com']);
    expect(launchStatus('business.hotline'))->toBe('fail');

    config(['notifications.hotline' => '09612-000111']);
    expect(launchStatus('business.hotline'))->toBe('pass');

    config(['notifications.store_url' => 'toymart.test']);
    expect(launchStatus('business.hotline'))->toBe('fail');
});

it('scans the storefront site config for placeholder contact and legal values', function (): void {
    // The real file in the repo still carries the owner's placeholders.
    expect(launchStatus('storefront.site_config'))->toBe('fail');

    $dir = sys_get_temp_dir().'/launch-sf-'.uniqid();
    mkdir($dir.'/toymartbd-app/storefront/lib', 0777, true);
    file_put_contents($dir.'/'.LaunchChecks::STOREFRONT_SITE_CONFIG, "hotline: { display: '09612-000111', placeholder: false },\nexport const legal = { tradeLicence: 'TRAD/1', bin: '0012' };\nexport const addressIsPlaceholder = false;\n");
    $original = app()->basePath();
    app()->setBasePath($dir);
    try {
        expect(launchStatus('storefront.site_config'))->toBe('pass');

        unlink($dir.'/'.LaunchChecks::STOREFRONT_SITE_CONFIG);
        expect(launchStatus('storefront.site_config'))->toBe('manual');
    } finally {
        app()->setBasePath($original);
    }
});

it('warns until the owner confirms the placeholder business values', function (): void {
    expect(launchStatus('business.placeholder_values'))->toBe('warn');

    $owner = User::factory()->create();
    Setting::put(DeliverySettings::PREFIX.'free_shipping_threshold', '2500.00', SettingGroup::Delivery, updatedBy: $owner->id);
    Setting::put(RiskSettings::KEY, ['first_order_hold_threshold' => '4000.00'], SettingGroup::Risk, updatedBy: $owner->id);
    config(['returns.window_days' => 10]);
    expect(launchStatus('business.placeholder_values'))->toBe('pass');
});

// ── G. people and data ───────────────────────────────────────────────────────────────────────

it('requires an active super admin and role-bearing staff', function (): void {
    expect(launchStatus('staff.super_admin'))->toBe('fail');

    User::factory()->create()->assignRole(Role::SuperAdmin->value);
    expect(launchStatus('staff.super_admin'))->toBe('pass')
        ->and(launchStatus('staff.roles'))->toBe('warn'); // only the owner

    User::factory()->create()->assignRole(Role::OrderOperator->value);
    expect(launchStatus('staff.roles'))->toBe('pass');

    User::factory()->create(); // can sign in, has no role
    expect(launchStatus('staff.roles'))->toBe('fail');
});

it('does not count the storefront service account or inactive users as staff', function (): void {
    User::factory()->create(['is_active' => false])->assignRole(Role::SuperAdmin->value);
    Artisan::call('toymart:storefront-token');

    expect(launchStatus('staff.super_admin'))->toBe('fail');
});

it('fails on demo logins, demo customers or demo products', function (): void {
    expect(launchStatus('data.no_demo'))->toBe('pass');

    User::factory()->create(['email' => 'manager@toymart.test']);
    expect(launchStatus('data.no_demo'))->toBe('fail');
    User::query()->delete();

    Customer::factory()->create(['phone' => '+8801711458231']);
    expect(launchDetail('data.no_demo'))->toContain('1 demo customer');
    Customer::query()->delete();

    Product::factory()->create(['slug' => array_key_first(Database\Seeders\DemoCatalogSeeder::PRODUCTS)]);
    expect(launchDetail('data.no_demo'))->toContain('1 demo product');
});

// ── H. monitoring ────────────────────────────────────────────────────────────────────────────

it('requires a Sentry DSN and wants the release SHA', function (): void {
    config(['sentry.dsn' => null]);
    expect(launchStatus('monitoring.sentry'))->toBe('fail');

    config(['sentry.dsn' => 'https://k@o1.ingest.sentry.io/2', 'sentry.release' => null]);
    expect(launchStatus('monitoring.sentry'))->toBe('warn');

    config(['sentry.release' => 'abc123def4567890']);
    expect(launchStatus('monitoring.sentry'))->toBe('pass');
});

it('wants JSON logs', function (): void {
    config(['logging.channels.stack.channels' => ['json']]);
    expect(launchStatus('monitoring.logs'))->toBe('pass');

    config(['logging.channels.stack.channels' => ['single']]);
    expect(launchStatus('monitoring.logs'))->toBe('warn');
});

it('still fails APP_DEBUG=true in production after the boot guard forced it off', function (): void {
    config(['app.debug' => true]);
    App\Support\ProductionSafety::enforce(true, app('config'), app('log'));

    expect(config('app.debug'))->toBeFalse()
        ->and(launchStatus('env.app_debug'))->toBe('fail');
});

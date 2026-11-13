<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Demo\DemoOrderSandbox;
use Illuminate\Database\Seeder;

/**
 * Transactional demo data for local development — every module that is built, filled
 * the way the business would fill it, so each admin screen, report and storefront page
 * has something real to show. Runs from DatabaseSeeder in `local`/`testing` only, and only
 * when `demo.transactions` is on (DEMO_SEED_TRANSACTIONS; on in `local` by default).
 *
 * Everything is written through the domain Actions, never by inserting rows, so the two
 * build invariants hold afterwards: stock movements sum to stock levels
 * (`toymart:inventory:reconcile`) and order financials equal the ledger
 * (`toymart:finance:verify`). No seeder here makes an external HTTP call — no SMS,
 * WhatsApp, courier, gateway or storefront revalidation.
 *
 * Order matters: orders need customers, coupons and received purchases (landed cost);
 * reviews need delivered orders; the courier settlement needs delivered COD orders.
 * Each seeder is idempotent — re-running `db:seed` adds nothing twice.
 *
 * The whole run sits inside DemoOrderSandbox: the queue is `sync` (ledger, aggregate and
 * risk listeners are queued, and on a developer's redis queue they would run later at the
 * wrong date), and outbound jobs — SMS/WhatsApp, login OTPs, cart recovery, storefront
 * revalidation — are recorded but never run, so a seed never texts an invented number even
 * when Admin → Integrations has switched the SMS driver to a real provider.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        DemoOrderSandbox::run(fn () => $this->call([
            DemoStaffSeeder::class,
            DemoCustomersSeeder::class,
            DemoMarketingSeeder::class,
            DemoPurchasingSeeder::class,
            DemoOrdersSeeder::class,
            DemoFinanceSeeder::class,
            DemoReviewsSeeder::class,
            DemoContentSeeder::class,
            DemoSeoSeeder::class,
        ]));
    }
}

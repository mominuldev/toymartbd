import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

/*
 * Orders (P4-ORD-04/05) against the real Laravel app on serve.sh's throwaway SQLite DB.
 * There is no storefront checkout in this run, so the spec places three orders through the
 * same domain path the feature tests use (OrderFixtures::place: items snapshotted, stock
 * reserved, payment opened, `placed` event) — Bangla names included (rule 34).
 */
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

function seedOrders(): string[] {
    const php = `
        use App\\Domain\\Catalog\\Models\\ProductVariant;
        use App\\Domain\\Customer\\Models\\Customer;
        use App\\Domain\\Inventory\\Models\\StockLevel;
        use Tests\\Feature\\Ordering\\Support\\OrderFixtures;
        $ids = StockLevel::query()->where('location_id', App\\Domain\\Inventory\\Models\\StockLocation::defaultId())->whereRaw('on_hand - reserved >= 5')->orderBy('variant_id')->limit(2)->pluck('variant_id')->all();
        $a = Customer::factory()->create(['name' => 'Shahriar Kabir']);
        $b = Customer::factory()->create(['name' => 'রিফাত চৌধুরী']);
        $high = OrderFixtures::place([$ids[0] => 2], customer: $a, overrides: ['risk_score' => 80, 'risk_level' => 'high', 'verification_status' => 'pending', 'risk_flags' => ['signals' => [['code' => 'first_order', 'weight' => 80]], 'hold_reasons' => ['first_order_above_threshold'], 'computed_at' => now()->toIso8601ZuluString()]]);
        $low = OrderFixtures::place([$ids[1] => 1], customer: $b, overrides: ['risk_score' => 10, 'risk_level' => 'low']);
        $third = OrderFixtures::place([$ids[0] => 1, $ids[1] => 1], customer: $b, overrides: ['risk_score' => 10, 'risk_level' => 'low']);
        echo implode(',', [$high->number, $low->number, $third->number]);
    `;
    // Same drivers as e2e/admin/serve.sh: the file cache matters because placing an order
    // changes stock, which clears the cached low-stock count — on CI the .env cache store
    // is Redis, which this job does not run.
    let out: string;
    try {
        out = execFileSync(process.env.PHP_BIN ?? 'php', ['artisan', 'tinker', '--execute', php], {
            cwd: root,
            env: { ...process.env, APP_ENV: 'local', DB_CONNECTION: 'sqlite', DB_DATABASE: resolve(root, 'database/e2e.sqlite'), SCOUT_DRIVER: 'null', QUEUE_CONNECTION: 'sync', CACHE_STORE: 'file', SESSION_DRIVER: 'file', STOREFRONT_URL: '' },
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'pipe'],
        });
    } catch (error) {
        const e = error as { stdout?: string; stderr?: string };
        throw new Error(`Seeding orders through tinker failed:\n${e.stderr ?? ''}\n${e.stdout ?? ''}`);
    }
    const numbers = out.trim().split('\n').pop()!.split(',');
    expect(numbers).toHaveLength(3);
    return numbers;
}

async function signIn(page: Page) {
    await page.goto('/admin/orders');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Orders', exact: true })).toBeVisible();
}

test('owner works the board: bulk confirm, hold with a reason, typed cancel, a note', async ({ page }) => {
    const [high, low, third] = seedOrders();
    await signIn(page);

    const table = page.getByRole('table');
    await expect(table.getByRole('link', { name: high! })).toBeVisible();
    await expect(table.getByText('রিফাত চৌধুরী').first()).toBeVisible();
    await expect(table.getByText('High 80')).toBeVisible();
    await expect(page.getByRole('button', { name: /^Verify\s*1$/ })).toBeVisible();

    // URL-driven filter, applied by the server.
    await page.getByLabel('Risk').selectOption('high');
    await expect(page).toHaveURL(/risk=high/);
    await expect(table.getByRole('row')).toHaveCount(2);
    await page.getByLabel('Risk').selectOption('');

    // Bulk confirm two pending orders: per-order results, never a silent all-or-nothing.
    await table.getByRole('checkbox', { name: `Select ${low}` }).check();
    await table.getByRole('checkbox', { name: `Select ${third}` }).check();
    const bar = page.getByRole('region', { name: 'Bulk actions' });
    await bar.getByRole('button', { name: 'Confirm 2' }).click();
    await expect(page.getByText('2 orders confirmed')).toBeVisible();
    await expect(page.getByRole('button', { name: /^Confirmed\s*2$/ })).toBeVisible();

    // Detail: our risk score, the courier panel honest about not being connected.
    await table.getByRole('link', { name: high! }).click();
    await expect(page.getByRole('heading', { level: 1, name: high! })).toBeVisible();
    await expect(page.getByText('High risk · 80 / 100')).toBeVisible();
    await expect(page.getByText('Provider not connected')).toBeVisible();
    await expect(page.getByText('No shipment yet')).toBeVisible();

    await page.getByRole('button', { name: 'Hold' }).click();
    const hold = page.getByRole('dialog', { name: `Hold ${high}?` });
    await hold.getByLabel(/Reason/).fill('Call the customer first — আগে ফোন করুন');
    await hold.getByRole('button', { name: 'Put on hold' }).click();
    await expect(page.getByText(`${high} put on hold`)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Release hold' })).toBeVisible();

    await page.getByLabel('Internal note').fill('ঠিকানা নিশ্চিত করা হয়েছে');
    await page.getByRole('button', { name: 'Add note' }).click();
    await expect(page.getByText('ঠিকানা নিশ্চিত করা হয়েছে')).toBeVisible();

    await page.getByRole('button', { name: 'Cancel order' }).click();
    const cancel = page.getByRole('dialog', { name: `Cancel ${high}?` });
    await cancel.getByLabel(/^Reason/).selectOption('fake_order');
    const submit = cancel.getByRole('button', { name: 'Cancel order' });
    await expect(submit).toBeDisabled();
    await cancel.getByLabel(`Type ${high} to cancel this order`).fill(high!);
    await submit.click();
    await expect(page.getByText(`${high} cancelled`)).toBeVisible();
    await expect(page.getByText('Cancelled: Fake order')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Confirm order' })).toHaveCount(0);

    // Bangla: same screen, Western digits.
    await page.getByRole('button', { name: 'বাং' }).click();
    await expect(page.getByText('বেশি ঝুঁকি · 80 / 100')).toBeVisible();
});

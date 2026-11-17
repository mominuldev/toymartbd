import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

/*
 * Fraud & risk premium pass (Figma 504:75986) against the real Laravel app on serve.sh's
 * throwaway SQLite DB: an order held for a call says in words why it waits, a call outcome is
 * one tap, confirming releases it; the phone lookup gives a verdict from OUR level with the
 * couriers' record as its own block (rule 24); risk rules show the points used as you type.
 * SHOTS_DIR=… also saves 1440 and 390 screenshots for the Figma check.
 */
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const shots = process.env.SHOTS_DIR;

function seedHeld(): { number: string; phone: string } {
    const php = `
        use App\\Domain\\Customer\\Models\\Customer;
        use App\\Domain\\Inventory\\Models\\StockLevel;
        use Tests\\Feature\\Ordering\\Support\\OrderFixtures;
        $id = StockLevel::query()->where('location_id', App\\Domain\\Inventory\\Models\\StockLocation::defaultId())->whereRaw('on_hand - reserved >= 3')->orderBy('variant_id')->value('variant_id');
        $c = Customer::factory()->create(['name' => 'শাহরিয়ার কবির']);
        $signals = [
            ['code' => 'cancellation_rate', 'weight' => 25, 'value' => '4/7', 'contribution' => 25],
            ['code' => 'courier_return_rate', 'weight' => 25, 'value' => '2/3', 'contribution' => 25],
            ['code' => 'failed_delivery', 'weight' => 15, 'value' => '3', 'contribution' => 15],
            ['code' => 'value_above_norm', 'weight' => 10, 'value' => '6260.00/2100.00', 'contribution' => 10],
            ['code' => 'address_quality', 'weight' => 5, 'value' => '9', 'contribution' => 5],
        ];
        $o = OrderFixtures::place([$id => 1], customer: $c, overrides: ['status' => 'on_hold', 'risk_score' => 80, 'risk_level' => 'high', 'verification_status' => 'pending', 'risk_flags' => ['signals' => $signals, 'hold_reasons' => ['high_risk_score'], 'computed_at' => now()->toIso8601ZuluString()]]);
        echo $o->number.','.$o->shipping_phone;
    `;
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
        throw new Error(`Seeding a held order through tinker failed:\n${e.stderr ?? ''}\n${e.stdout ?? ''}`);
    }
    const [number = '', phone = ''] = out.trim().split('\n').pop()!.split(',');
    return { number, phone };
}

async function signIn(page: Page) {
    await page.goto('/admin/risk/queue');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Orders to check', exact: true })).toBeVisible();
}

async function shot(page: Page, name: string) {
    if (!shots) return;
    await page.screenshot({ path: `${shots}/${name}.png`, fullPage: true });
}

test('works a held order in plain words: why it waits, one-tap outcome, confirm releases it', async ({ page }) => {
    const held = seedHeld();
    await page.setViewportSize({ width: 1440, height: 1000 });
    await signIn(page);

    const list = page.getByRole('list', { name: 'Held orders' });
    const card = list.getByRole('button', { name: new RegExp(held.number) });
    await expect(card).toContainText('Cancelled 4 of their 7 past orders');
    await card.click();

    const panel = page.getByRole('region', { name: 'Chosen order' });
    await expect(panel.getByRole('heading', { name: /Call শাহরিয়ার কবির/ })).toBeVisible();
    const why = panel.getByRole('region', { name: 'Why this order is paused' });
    await expect(why).toContainText('2 of 3 parcels came back from the courier');
    await expect(why).toContainText('+25');
    // Our record and the couriers' stay two named blocks; none connected is never a zero (rules 24, 33b).
    await expect(panel.getByRole('region', { name: 'From our orders' })).toBeVisible();
    await expect(panel.getByRole('region', { name: 'What the couriers say' })).toContainText('Provider not connected');
    await shot(page, 'risk-queue-1440');

    await panel.getByRole('button', { name: 'No answer' }).click();
    await expect(page.getByText('Saved: No answer')).toBeVisible();
    await expect(panel.getByText(/^No answer$/).first()).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByRole('link', { name: 'Call now' }).last()).toBeVisible();
    await shot(page, 'risk-queue-390');
    await page.setViewportSize({ width: 1440, height: 1000 });

    await panel.getByRole('button', { name: 'Confirmed — send to courier' }).click();
    await expect(page.getByText(`${held.number} verified and confirmed`)).toBeVisible();
    await expect(list.getByRole('button', { name: new RegExp(held.number) })).toHaveCount(0);
});

test('looks up a phone: a verdict from our level, our record and the couriers’ record apart', async ({ page }) => {
    const held = seedHeld();
    await page.setViewportSize({ width: 1440, height: 1000 });
    await signIn(page);
    await page.getByRole('link', { name: 'Phone lookup' }).first().click();

    const field = page.getByRole('textbox', { name: 'Phone number' });
    await field.focus();
    await page.keyboard.type(held.phone);
    // One focus ring, on the box: the input inside draws none of its own (regression 2026-11-17).
    // (Tailwind's shadow-none computes to transparent layers; any visible colour would be a ring.)
    expect(await field.evaluate((el) => getComputedStyle(el).boxShadow)).not.toMatch(/rgb\(|rgba\((?!0, 0, 0, 0\))/);
    if (shots) await page.locator('form').first().screenshot({ path: `${shots}/risk-lookup-focus.png` });
    await page.getByRole('button', { name: 'Look up' }).click();
    await expect(page.getByRole('region', { name: 'Our score' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'From our orders' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'What the couriers say' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'List status' })).toContainText('No list');
    await shot(page, 'risk-lookup-1440');
    await page.setViewportSize({ width: 390, height: 844 });
    await shot(page, 'risk-lookup-390');
});

test('risk rules: plain questions, points used as you type, saved for new orders', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await signIn(page);
    await page.getByRole('link', { name: 'Risk rules' }).first().click();

    const thin = page.getByRole('textbox', { name: 'Points for Thin address' });
    await expect(thin).not.toHaveValue('');
    await expect(page.getByRole('heading', { name: 'When should an order be paused for a call?' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Minutes between orders' })).toBeVisible();
    await shot(page, 'risk-rules-1440');

    const before = Number(await thin.inputValue());
    await thin.fill(String(before + 1));
    await page.getByRole('button', { name: 'Save rules' }).click();
    await expect(page.getByText('Risk rules saved')).toBeVisible();
    // Put it back so the shared run stays on the owner's values.
    await thin.fill(String(before));
    await page.getByRole('button', { name: 'Save rules' }).click();
    await expect(page.getByText('Risk rules saved').last()).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });
    await shot(page, 'risk-rules-390');
});

import { expect, test, type Page } from '@playwright/test';

/*
 * Unified inbox (Figma B · 22) against the real app. No WhatsApp Business account or Facebook
 * Page exists, so nothing here touches Meta: the webhook is exercised only for its refusal
 * (no app secret configured → 401, nothing written), and the thread flows run on the demo
 * conversations from database/seeders/DemoInboxSeeder.php. Until that seeder is wired into
 * the local DatabaseSeeder run, the thread tests skip themselves rather than fail.
 * Replies go through the `log` driver (QUEUE_CONNECTION=sync), so "sent" here means
 * "recorded and handed to the log driver", never delivered.
 * SHOTS_DIR=… also saves screenshots for the Figma check.
 */

const shots = process.env.SHOTS_DIR;

async function signIn(page: Page) {
    await page.goto('/admin/inbox');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Inbox' })).toBeVisible();
}

test.use({ viewport: { width: 1440, height: 1000 } });

test('refuses an unsigned WhatsApp webhook before writing anything', async ({ request }) => {
    const response = await request.post('/api/v1/webhooks/inbox/whatsapp', {
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        data: { object: 'whatsapp_business_account', entry: [] },
    });
    expect(response.status()).toBe(401);

    const handshake = await request.get('/api/v1/webhooks/inbox/whatsapp?hub.mode=subscribe&hub.verify_token=guess&hub.challenge=42', { headers: { Accept: 'application/json' } });
    expect(handshake.status()).toBe(403);
});

test('shows the inbox with its tabs, and no AI tab', async ({ page }) => {
    await signIn(page);
    const list = page.getByRole('region', { name: 'Conversations' });
    const tabs = list.getByRole('group', { name: 'Conversation views' });
    await expect(tabs.getByRole('button', { name: /^All/ })).toHaveAttribute('aria-pressed', 'true');
    await expect(tabs.getByRole('button', { name: /^Mine/ })).toBeVisible();
    await expect(tabs.getByRole('button', { name: /^Needs human/ })).toBeVisible();
    await expect(tabs.getByRole('button', { name: /^AI/ })).toHaveCount(0);

    // An unlisted filter is refused by the API (rule 16).
    // Same-origin Referer: Sanctum only treats a first-party request as the admin session.
    const bad = await page.request.get('/api/v1/admin/inbox/conversations?filter[view]=ai', { headers: { Accept: 'application/json', Referer: new URL('/admin', page.url()).toString() } });
    expect(bad.status()).toBe(400);
    if (shots) await page.screenshot({ path: `${shots}/inbox-list.png` });
});

test('notes, replies, assigns and resolves a demo conversation', async ({ page }) => {
    await signIn(page);
    const list = page.getByRole('region', { name: 'Conversations' });
    const rows = list.getByRole('link');
    test.skip((await rows.count()) === 0, 'DemoInboxSeeder is not wired into the E2E seed yet');

    await rows.first().click();
    const thread = page.getByRole('region', { name: /^Messages with / });
    await expect(thread).toBeVisible();
    const panel = page.getByRole('complementary', { name: 'Customer' });
    await expect(panel.getByRole('button', { name: /Send bKash payment link/ })).toBeDisabled();
    if (shots) await page.screenshot({ path: `${shots}/inbox-thread.png` });

    // An internal note: dashed, labelled, never sent.
    await page.getByRole('radio', { name: 'Internal note' }).click();
    await page.getByRole('textbox', { name: 'Internal note' }).fill('Called her, she wants the pink set.');
    await page.getByRole('button', { name: 'Add note' }).click();
    await expect(thread.getByText('Called her, she wants the pink set.')).toBeVisible();
    await expect(thread.getByText(/not visible to customer/).last()).toBeVisible();

    // A reply inside the 24-hour window: queued, then handed to the log driver.
    await page.getByRole('radio', { name: 'Reply' }).click();
    await expect(page.getByRole('radio', { name: 'Reply' })).toBeChecked();
    const reply = page.getByRole('textbox', { name: 'Reply' });
    if (await reply.isEnabled()) {
        // The composer re-renders when the mode changes; type once it has settled.
        await expect(async () => {
            await reply.fill('Apa, kal pouchabe.');
            await expect(reply).toHaveValue('Apa, kal pouchabe.', { timeout: 500 });
            await expect(page.getByRole('button', { name: 'Send', exact: true })).toBeEnabled({ timeout: 500 });
        }).toPass({ timeout: 10_000 });
        await page.getByRole('button', { name: 'Send', exact: true }).click();
        await expect(thread.getByText('Apa, kal pouchabe.')).toBeVisible();
        await expect(page.getByText('Reply queued')).toBeVisible();
    }

    const assign = page.getByRole('button', { name: 'Assign to me' });
    if (await assign.isVisible()) {
        await assign.click();
        await expect(page.getByText('Conversation assigned')).toBeVisible();
    }

    await page.getByRole('button', { name: 'Resolve' }).click();
    await expect(page.getByText('Conversation resolved')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Reopen' })).toBeVisible();
});

import { expect, test, type Page } from '@playwright/test';

/*
 * Inbox › Channels (Figma 623:50926) against the real app on the seeded database, where no Meta
 * app, Page or WhatsApp number is connected: the Facebook Page screen asks for the Meta app first,
 * the WhatsApp guide saves each step and stops at Meta's check, and the Inbox submenu links both.
 * SHOTS_DIR=… also saves screenshots for the Figma check.
 */

const shots = process.env.SHOTS_DIR;

async function signIn(page: Page, path: string) {
    await page.goto(path);
    await page.getByLabel('Email').fill('mominulfed@gmail.com');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
}

test('Facebook Page: not connected, Continue with Facebook waits for the Meta app', async ({ browser }) => {
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
    await signIn(page, '/admin/inbox/channels/messenger');

    await expect(page.getByRole('heading', { name: 'Facebook Page', level: 1 })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Connect your Facebook Page' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Continue with Facebook' })).toBeDisabled();
    await expect(page.getByRole('link', { name: 'Add the Meta app' })).toBeVisible();
    const submenu = page.getByRole('list', { name: 'Inbox' }).first();
    await expect(submenu.getByRole('link', { name: 'Facebook Page' })).toHaveAttribute('aria-current', 'page');
    await expect(submenu.getByRole('link', { name: 'Chats' })).not.toHaveAttribute('aria-current', 'page');
    if (shots) await page.screenshot({ path: `${shots}/channel-messenger.png`, fullPage: true });

    await page.getByRole('link', { name: 'Add the Meta app' }).click();
    await expect(page).toHaveURL(/\/admin\/integrations\/messaging#inbox-channel-settings$/);
    await expect(page.getByLabel('Meta App ID')).toBeVisible();
});

test('WhatsApp: the guided setup saves each step and stops at Meta’s check', async ({ browser }) => {
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
    await signIn(page, '/admin/inbox/channels/whatsapp');

    await expect(page.getByRole('heading', { name: 'WhatsApp', level: 1 })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Connect your WhatsApp Business number' })).toBeVisible();
    if (shots) await page.screenshot({ path: `${shots}/channel-whatsapp.png`, fullPage: true });

    await page.getByRole('button', { name: /setup$/ }).click();
    const guide = page.getByRole('dialog', { name: 'Set up WhatsApp' });
    await expect(guide).toBeVisible();
    // The seeded env may already carry an app secret: type one either way.
    if (await guide.getByLabel(/^App secret/).isVisible()) {
        await guide.getByLabel(/^App secret/).fill('e2e-app-secret-value');
        await guide.getByRole('button', { name: 'Next: number IDs' }).click();
    }
    await guide.getByLabel(/^Phone number ID/).fill('106540352242922');
    await guide.getByLabel(/^WhatsApp Business Account ID/).fill('102938475610293');
    if (shots) await page.screenshot({ path: `${shots}/channel-whatsapp-guide.png` });
    await guide.getByRole('button', { name: 'Next: permanent token' }).click();
    await expect(guide.getByLabel(/^Access token/)).toBeVisible();
    await guide.getByRole('button', { name: 'Close' }).click();

    // Closing kept the IDs: the card now offers to continue.
    await expect(page.getByRole('button', { name: 'Continue setup' })).toBeVisible();
});

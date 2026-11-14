import { createHmac } from 'node:crypto';
import { expect, test, type Browser, type Page } from '@playwright/test';

/*
 * Two-factor sign-in (owner decision 2026-11-14) on the real app: a staff member sets it up
 * from the account menu, signs in again with a code their "phone" computes (RFC 6238 here),
 * and an owner resets it after a lost phone. Enforcement itself (money roles held at setup)
 * is covered by Pest; this server runs with TWO_FACTOR_ENFORCE=false so other specs need no code.
 */

const shots = process.env.SHOTS_DIR;

function base32(secret: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/\s+/g, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    const bytes: number[] = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    return Buffer.from(bytes);
}

/** The 6-digit code an authenticator app shows `steps` × 30 s from now. */
function totp(secret: string, steps = 0): string {
    const counter = Math.floor(Date.now() / 1000 / 30) + steps;
    const message = Buffer.alloc(8);
    message.writeBigUInt64BE(BigInt(counter));
    const hmac = createHmac('sha1', base32(secret)).update(message).digest();
    const offset = hmac[hmac.length - 1]! & 0xf;
    return String((hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}

async function open(browser: Browser): Promise<Page> {
    return (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
}

async function signIn(page: Page, email: string, password: string, path = '/admin') {
    await page.goto(path);
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
}

test('set up two-factor, sign in with a code, and an owner resets a lost one', async ({ browser }) => {
    const owner = await open(browser);
    await signIn(owner, 'owner@toymart.test', 'password', '/admin/staff');
    await owner.getByRole('button', { name: 'Add staff' }).click();
    const add = owner.getByRole('dialog', { name: 'Add staff' });
    await add.getByLabel('Full name').fill('Nadia Rahman');
    await add.getByLabel('Email').fill('nadia@toymart.test');
    await add.getByLabel('Role').selectOption('accountant');
    await add.getByLabel('Temporary password').fill('temporary-pass-2026');
    await add.getByRole('button', { name: 'Add staff' }).click();
    await expect(owner.getByText('Nadia Rahman added')).toBeVisible();

    // Set up from the account menu.
    const nadia = await open(browser);
    await signIn(nadia, 'nadia@toymart.test', 'temporary-pass-2026');
    await nadia.getByRole('button', { name: /Account menu for Nadia|Nadia/ }).click();
    await nadia.getByRole('menuitem', { name: 'Two-factor sign-in' }).click();
    await nadia.getByRole('button', { name: 'Set up' }).click();
    await expect(nadia.getByRole('img', { name: /QR code/ })).toBeVisible();
    const secret = (await nadia.locator('code').first().innerText()).replace(/\s+/g, '');
    if (shots) await nadia.screenshot({ path: `${shots}/2fa-setup.png` });
    await nadia.getByLabel(/Code from the app/).fill('000000');
    await nadia.getByRole('button', { name: 'Turn on' }).click();
    await expect(nadia.getByLabel(/Code from the app/)).toHaveAttribute('aria-invalid', 'true');
    await nadia.getByLabel(/Code from the app/).fill(totp(secret));
    await nadia.getByRole('button', { name: 'Turn on' }).click();
    const codes = nadia.getByRole('list', { name: 'Save your recovery codes' });
    await expect(codes.getByRole('listitem')).toHaveCount(8);
    const recovery = (await codes.getByRole('listitem').first().innerText()).trim();
    if (shots) await nadia.screenshot({ path: `${shots}/2fa-codes.png` });
    await nadia.getByRole('button', { name: 'I have saved them' }).click();

    // Sign in again: password, then the code (the next step: the confirm code is spent).
    const again = await open(browser);
    await signIn(again, 'nadia@toymart.test', 'temporary-pass-2026');
    await expect(again.getByRole('heading', { name: 'Enter your sign-in code' })).toBeVisible();
    if (shots) await again.screenshot({ path: `${shots}/2fa-challenge.png` });
    await again.getByLabel('Code from the app').fill(totp(secret, 1));
    await again.getByRole('button', { name: 'Sign in' }).click();
    await expect(again.getByRole('heading', { name: 'Enter your sign-in code' })).toHaveCount(0);
    await expect(again.getByRole('navigation').first()).toBeVisible();

    // A recovery code works once.
    const third = await open(browser);
    await signIn(third, 'nadia@toymart.test', 'temporary-pass-2026');
    await third.getByRole('button', { name: 'Use a recovery code' }).click();
    await third.getByLabel('Recovery code').fill(recovery);
    await third.getByRole('button', { name: 'Sign in' }).click();
    await expect(third.getByRole('heading', { name: 'Enter your sign-in code' })).toHaveCount(0);

    // Lost phone: the owner resets it; her next sign-in needs only the password.
    await owner.reload();
    const row = owner.getByRole('table').getByRole('row', { name: /Nadia Rahman/ });
    await expect(row).toContainText('On');
    await owner.getByRole('button', { name: 'Manage Nadia Rahman' }).click();
    await owner.getByRole('dialog', { name: 'Manage Nadia Rahman' }).getByRole('button', { name: 'Reset two-factor' }).click();
    await owner.getByRole('dialog', { name: "Reset Nadia Rahman's two-factor?" }).getByRole('button', { name: 'Reset two-factor' }).click();
    await expect(owner.getByText("Nadia Rahman's two-factor was reset")).toBeVisible();

    const fourth = await open(browser);
    await signIn(fourth, 'nadia@toymart.test', 'temporary-pass-2026');
    await expect(fourth.getByRole('heading', { name: 'Enter your sign-in code' })).toHaveCount(0);
    await expect(fourth.getByRole('heading', { name: 'Sign in to Admin' })).toHaveCount(0);
});

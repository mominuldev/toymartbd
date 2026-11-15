import { expect, test } from '@playwright/test';

import { bn } from '../lib/i18n/bn';
import { en } from '../lib/i18n/en';
import { STOREFRONT_URL } from './constants';

// Click-to-chat (WhatsApp wa.me and Messenger m.me), JavaScript off: plain server-rendered links that
// open a new tab, each only when the owner has set it (the mock sets WhatsApp +8801812345678 and the
// Messenger Page `toymartbd`). The product page prefills the WhatsApp text with the toy's name and
// canonical URL in the page's language. No third-party script.

test.use({ javaScriptEnabled: false });

const SLUG = 'lego-classic-creative-bricks';

for (const [locale, t, prefix, name] of [
    ['en', en, '', 'LEGO Classic Creative Bricks 484 pcs'],
    ['bn', bn, '/bn', 'লেগো ক্লাসিক ক্রিয়েটিভ ব্রিকস 484 পিস'],
] as const) {
    test(`product page (${locale}): WhatsApp with the toy and its canonical URL prefilled, and Messenger`, async ({ page }) => {
        await page.goto(`${prefix}/products/${SLUG}`);
        const chat = page.getByRole('region', { name: t.chat.productTitle });
        await expect(chat).toBeVisible();

        const wa = chat.getByRole('link', { name: t.chat.whatsapp });
        await expect(wa).toHaveAttribute('target', '_blank');
        await expect(wa).toHaveAttribute('rel', 'noopener noreferrer');
        const href = new URL((await wa.getAttribute('href'))!);
        expect(`${href.origin}${href.pathname}`).toBe('https://wa.me/8801812345678');
        const text = href.searchParams.get('text') ?? '';
        expect(text).toContain(name);
        expect(text).toContain(`${STOREFRONT_URL}${prefix}/products/${SLUG}`);
        expect(text).not.toContain('?variant=');

        const messenger = chat.getByRole('link', { name: t.chat.messenger });
        await expect(messenger).toHaveAttribute('href', 'https://m.me/toymartbd');
        await expect(messenger).toHaveAttribute('target', '_blank');
        // Accessible name says it opens a new tab; the touch target is the 44 px button.
        await expect(messenger).toHaveAccessibleName(`${t.chat.messenger} ${t.chat.newTab}`);
        expect((await messenger.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    });
}

test('contact page: both chat buttons under the page content, in Bangla too', async ({ page }) => {
    expect((await page.goto('/contact'))?.status()).toBe(200);
    const chat = page.getByRole('region', { name: en.chat.title });
    await expect(chat.getByRole('link', { name: en.chat.whatsapp })).toHaveAttribute('href', 'https://wa.me/8801812345678');
    await expect(chat.getByRole('link', { name: en.chat.messenger })).toHaveAttribute('href', 'https://m.me/toymartbd');

    await page.goto('/bn/contact');
    const bnChat = page.getByRole('region', { name: bn.chat.title });
    await expect(bnChat.getByRole('link', { name: bn.chat.whatsapp })).toHaveAttribute('target', '_blank');
    await expect(bnChat.getByRole('link', { name: bn.chat.messenger })).toBeVisible();
});

test('other CMS pages carry no chat block', async ({ page }) => {
    await page.goto('/delivery-policy');
    await expect(page.getByRole('region', { name: en.chat.title })).toHaveCount(0);
});

test('footer: WhatsApp and Messenger open in a new tab; no chat SDK is loaded', async ({ page }) => {
    await page.goto('/');
    const footer = page.locator('footer');
    await expect(footer.getByRole('link', { name: en.footer.helpTile.whatsapp })).toHaveAttribute('target', '_blank');
    const messenger = footer.getByRole('link', { name: en.chat.messenger });
    await expect(messenger).toHaveAttribute('href', 'https://m.me/toymartbd');
    await expect(messenger).toHaveAttribute('rel', 'noopener noreferrer');
    const html = await page.content();
    expect(html).not.toMatch(/connect\.facebook\.net\/[^"]*(?:sdk|customerchat)/);
    expect(html).not.toContain('xfbml');
});

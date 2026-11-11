import { expect, test } from '@playwright/test';

// The language toggle and the first-visit Bangla suggestion (JavaScript enabled).

test.describe('a browser that prefers Bangla', () => {
    test.use({ locale: 'bn-BD' });

    test('is shown a dismissible suggestion on English pages, never redirected', async ({ page, context }) => {
        const res = await page.goto('/brands');
        expect(res?.url()).toMatch(/\/brands$/);
        const banner = page.getByRole('region', { name: 'ভাষার পরামর্শ' });
        await expect(banner).toBeVisible();
        await expect(banner.getByRole('link', { name: 'বাংলায় দেখুন' })).toHaveAttribute('href', '/bn/brands');

        await banner.getByRole('button', { name: 'ইংরেজিতেই থাকুন' }).click();
        await expect(banner).toBeHidden();
        expect((await context.cookies()).find((c) => c.name === 'tm_locale')?.value).toBe('en');

        await page.reload();
        await expect(page.locator('h1')).toHaveText('Brands');
        await expect(page.getByRole('region', { name: 'ভাষার পরামর্শ' })).toHaveCount(0);
    });

    test('is not shown the suggestion on Bangla pages', async ({ page }) => {
        await page.goto('/bn/brands');
        await expect(page.locator('h1')).toHaveText('ব্র্যান্ড');
        await expect(page.getByRole('region', { name: 'ভাষার পরামর্শ' })).toHaveCount(0);
    });
});

test('an English browser sees no suggestion; the toggle switches page and remembers the choice', async ({ page, context }) => {
    await page.goto('/brands/lego');
    await expect(page.locator('h1')).toHaveText('LEGO');
    await expect(page.getByRole('region', { name: 'ভাষার পরামর্শ' })).toHaveCount(0);

    await page.locator('header a[hreflang="bn"]').click();
    await expect(page).toHaveURL(/\/bn\/brands\/lego$/);
    await expect(page.locator('h1')).toHaveText('লেগো');
    expect((await context.cookies()).find((c) => c.name === 'tm_locale')?.value).toBe('bn');

    // Back on an English URL, the remembered choice is offered — not forced.
    await page.goto('/brands/lego');
    await expect(page.locator('h1')).toHaveText('LEGO');
    await expect(page.getByRole('region', { name: 'ভাষার পরামর্শ' })).toBeVisible();
});

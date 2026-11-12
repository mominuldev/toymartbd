import { expect, test, type Page } from '@playwright/test';

/**
 * The media picker on Product edit (Figma 303:55922): two library images are chosen in a
 * deliberate order and land in the gallery in that order (docs/api/media-library.md,
 * "attach from the library"). Signs in once for the whole spec.
 */
test.describe.configure({ mode: 'serial' });

let page: Page;

test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await page.goto('/admin/products');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Products', exact: true })).toBeVisible();
});

test.afterAll(async () => {
    await page.close();
});

/**
 * Upload a 1200 × 1200 PNG drawn on a canvas to POST /media, from inside the signed-in
 * page so the cookie session and the XSRF header are the admin's own.
 */
async function uploadToLibrary(filename: string, altEn: string, altBn: string, colour: string): Promise<number> {
    return page.evaluate(
        async ({ filename, altEn, altBn, colour }) => {
            const canvas = document.createElement('canvas');
            canvas.width = 1200;
            canvas.height = 1200;
            const ctx = canvas.getContext('2d')!;
            ctx.fillStyle = colour;
            ctx.fillRect(0, 0, 1200, 1200);
            const blob = await new Promise<Blob>((resolve) => canvas.toBlob((b) => resolve(b!), 'image/png'));
            const xsrf = decodeURIComponent(/(?:^|; )XSRF-TOKEN=([^;]+)/.exec(document.cookie)?.[1] ?? '');
            const folders = (await (await fetch('/api/v1/admin/media/folders', { headers: { Accept: 'application/json' } })).json()) as { data: { id: number; system_key: string | null }[] };
            const products = folders.data.find((f) => f.system_key === 'products')!;
            const body = new FormData();
            body.append('file', new File([blob], filename, { type: 'image/png' }));
            body.append('folder_id', String(products.id));
            body.append('alt_text', altEn);
            body.append('alt_text_bn', altBn);
            const response = await fetch('/api/v1/admin/media', { method: 'POST', body, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf } });
            if (response.status !== 201) throw new Error(`upload failed: ${response.status} ${await response.text()}`);
            return ((await response.json()) as { id: number }).id;
        },
        { filename, altEn, altBn, colour },
    );
}

test('owner picks two library images and they land in the gallery in pick order', async () => {
    // Bangla and English fixtures on every path (CLAUDE.md rule 34).
    await uploadToLibrary('picker-e2e-front.png', 'Picker front', 'পিকার সামনে', 'rgb(220, 52, 66)');
    await uploadToLibrary('picker-e2e-back.png', 'Picker back', 'পিকার পেছন', 'rgb(52, 120, 220)');

    await page.goto('/admin/products');
    await page.getByRole('table').getByRole('link').first().click();
    await expect(page).toHaveURL(/\/admin\/products\/\d+/);

    const gallery = page.getByRole('region', { name: 'Product images' });
    await expect(gallery).toBeVisible();
    const altFields = gallery.getByLabel(/^Alt text \(English\), image \d+$/);
    const before = await altFields.count();

    const open = gallery.getByRole('button', { name: 'Choose from library' });
    await expect(open).toBeEnabled();
    await open.click();

    const picker = page.getByRole('dialog', { name: 'Choose product images' });
    await expect(picker).toBeVisible();
    await expect(picker.getByRole('button', { name: /^Products/ })).toHaveAttribute('aria-pressed', 'true');

    // Back first, then front: the numbers are the gallery order.
    await picker.getByRole('button', { name: /^picker-e2e-back/ }).click();
    await picker.getByRole('button', { name: /^picker-e2e-front/ }).click();
    await expect(picker.getByRole('button', { name: /^picker-e2e-back.*pick 1$/ })).toHaveAttribute('aria-pressed', 'true');
    await expect(picker.getByRole('button', { name: /^picker-e2e-front.*pick 2$/ })).toHaveAttribute('aria-pressed', 'true');

    await picker.getByRole('button', { name: 'Insert 2 images' }).click();
    await expect(picker).toBeHidden();

    await expect(altFields).toHaveCount(before + 2);
    await expect(gallery.getByLabel(`Alt text (English), image ${before + 1}`, { exact: true })).toHaveValue('Picker back');
    await expect(gallery.getByLabel(`Alt text (English), image ${before + 2}`, { exact: true })).toHaveValue('Picker front');
    await expect(gallery.getByLabel(`Alt text (Bangla), image ${before + 1}`, { exact: true })).toHaveValue('পিকার পেছন');

    // Inserting is its own write: the product form is left clean, with nothing pending to save.
    await expect(page.getByText('No unsaved changes')).toBeVisible();
});

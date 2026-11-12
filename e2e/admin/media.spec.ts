import { deflateSync } from 'node:zlib';
import { expect, test, type Page } from '@playwright/test';

/**
 * Media library (admin spec §B9, docs/api/media-library.md) against the real Laravel app:
 * upload real PNGs, fix a missing Bangla alt, get blocked deleting a file a product uses,
 * and delete an unused one. One sign-in for the whole spec.
 */

/** A real, decodable RGB PNG (the server sniffs content and decodes with GD). */
function png(width: number, height: number, rgb: [number, number, number]): Buffer {
    const crcTable = Array.from({ length: 256 }, (_, n) => {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        return c >>> 0;
    });
    const crc = (buf: Buffer) => {
        let c = 0xffffffff;
        for (const byte of buf) c = crcTable[(c ^ byte) & 0xff]! ^ (c >>> 8);
        return (c ^ 0xffffffff) >>> 0;
    };
    const chunk = (type: string, data: Buffer) => {
        const len = Buffer.alloc(4);
        len.writeUInt32BE(data.length);
        const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
        const sum = Buffer.alloc(4);
        sum.writeUInt32BE(crc(body));
        return Buffer.concat([len, body, sum]);
    };
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(width, 0);
    ihdr.writeUInt32BE(height, 4);
    ihdr[8] = 8; // bit depth
    ihdr[9] = 2; // RGB
    const row = Buffer.concat([Buffer.from([0]), Buffer.from(Array.from({ length: width }, () => rgb).flat())]);
    const raw = Buffer.concat(Array.from({ length: height }, () => row));
    return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

async function signIn(page: Page) {
    await page.goto('/admin/store/media');
    await expect(page.getByRole('heading', { name: 'Sign in to Admin' })).toBeVisible();
    await page.getByLabel('Email').fill('owner@toymart.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Media library', exact: true })).toBeVisible();
}

/** A JSON write from inside the page, so the cookie session and XSRF header are the admin's own. */
async function adminFetch(page: Page, method: string, path: string, body?: unknown): Promise<{ status: number; json: unknown }> {
    return page.evaluate(
        async ({ method, path, body }) => {
            const xsrf = decodeURIComponent(/(?:^|; )XSRF-TOKEN=([^;]+)/.exec(document.cookie)?.[1] ?? '');
            const response = await fetch(`/api/v1/admin${path}`, {
                method,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
                body: body === undefined ? undefined : JSON.stringify(body),
            });
            return { status: response.status, json: await response.json().catch(() => null) };
        },
        { method, path, body },
    );
}

test('owner uploads, fills the Bangla alt, is blocked deleting a used file and deletes an unused one', async ({ page }) => {
    await signIn(page);
    const stamp = Date.now().toString(36);
    const used = `e2e-used-${stamp}.png`;
    const spare = `e2e-spare-${stamp}.png`;

    await test.step('upload two real PNGs into Products', async () => {
        // The E2E seed has no product photos, so the library may start empty (no rail):
        // open the Products folder by its id, which is what the rail button does.
        const folders = await adminFetch(page, 'GET', '/media/folders');
        const products = (folders.json as { data: { id: number; system_key: string | null }[] }).data.find((f) => f.system_key === 'products')!;
        await page.goto(`/admin/store/media?folder=${products.id}`);
        await expect(page.getByRole('navigation', { name: 'Media filters' }).getByRole('button', { name: /^Products/ })).toHaveAttribute('aria-pressed', 'true');
        await page.getByTestId('media-upload-input').setInputFiles([
            { name: used, mimeType: 'image/png', buffer: png(64, 64, [220, 52, 66]) },
            { name: spare, mimeType: 'image/png', buffer: png(48, 48, [80, 70, 229]) },
        ]);
        const queue = page.getByRole('region', { name: 'Uploads' });
        await expect(queue.getByRole('heading', { name: 'Uploading 2 files to Products' })).toBeVisible();
        await expect(queue.getByText('2 done')).toBeVisible({ timeout: 30_000 });
        // New images without alt text are flagged right away.
        await expect(page.getByText('2 new images have no alt text yet.', { exact: false })).toBeVisible();
    });

    const grid = page.getByRole('list', { name: 'Media files' });
    const usedTile = grid.getByRole('button', { name: used });

    await test.step('the new file is listed under Missing Bangla alt until its Bangla alt is set', async () => {
        await expect(usedTile).toContainText('Alt missing');
        const rail = page.getByRole('navigation', { name: 'Media filters' });
        await rail.getByRole('button', { name: /^Missing Bangla alt/ }).click();
        await expect(grid.getByRole('button', { name: used })).toBeVisible();

        await grid.getByRole('button', { name: used }).click();
        const panel = page.getByRole('complementary', { name: 'Asset details' });
        await expect(panel.getByText(used, { exact: true })).toBeVisible();
        const en = panel.getByLabel('English', { exact: false });
        await en.fill('Red test square for the media spec');
        await en.press('Enter');
        const bn = panel.getByRole('textbox', { name: /বাংলা/ });
        await bn.fill('মিডিয়া পরীক্ষার লাল বর্গ');
        await bn.press('Enter');
        await expect(bn).not.toHaveAttribute('aria-invalid', 'true');

        // It leaves the "Missing Bangla alt" view; in Products it now carries the complete chip.
        await expect(grid.getByRole('button', { name: used })).toHaveCount(0);
        await rail.getByRole('button', { name: /^Missing Bangla alt/ }).click();
        await expect(grid.getByRole('button', { name: used })).toContainText('Alt EN · বাং');
    });

    await test.step('a file used by a product cannot be deleted', async () => {
        const list = await adminFetch(page, 'GET', `/media?filter[search]=${encodeURIComponent(used)}`);
        const mediaId = (list.json as { data: { id: number }[] }).data[0]!.id;
        const products = await adminFetch(page, 'GET', '/products?per_page=1');
        const productId = (products.json as { data: { id: number }[] }).data[0]!.id;
        const attach = await adminFetch(page, 'POST', `/products/${productId}/images`, { media_id: mediaId });
        expect(attach.status).toBe(201);

        await page.goto(`/admin/store/media?asset=${mediaId}`);
        const panel = page.getByRole('complementary', { name: 'Asset details' });
        await expect(panel.getByRole('heading', { name: 'Used in 1 place' })).toBeVisible();
        await expect(panel.getByRole('button', { name: 'Delete file' })).toBeDisabled();
        await expect(panel.getByText(/Can’t delete while it’s used in 1 place/)).toBeVisible();
        await expect(panel.getByRole('link', { name: /^Open / })).toHaveAttribute('href', `/admin/products/${productId}`);

        // The server enforces it too (A25 rule 2): 409 media-in-use with the usages.
        const refused = await adminFetch(page, 'DELETE', `/media/${mediaId}`);
        expect(refused.status).toBe(409);
        expect((refused.json as { type: string }).type).toBe('https://toymartbd.com/problems/media-in-use');
    });

    await test.step('an unused file is deleted after confirming', async () => {
        await page.goto('/admin/store/media');
        await page.getByRole('list', { name: 'Media files' }).getByRole('button', { name: spare }).click();
        const panel = page.getByRole('complementary', { name: 'Asset details' });
        await expect(panel.getByText('Not used anywhere yet')).toBeVisible();
        await panel.getByRole('button', { name: 'Delete file' }).click();
        const dialog = page.getByRole('dialog', { name: `Delete ${spare}?` });
        await dialog.getByRole('button', { name: 'Delete file' }).click();
        await expect(page.getByText(`${spare} deleted`)).toBeVisible();
        await expect(page.getByRole('list', { name: 'Media files' }).getByRole('button', { name: spare })).toHaveCount(0);
    });
});

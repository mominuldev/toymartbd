import { expect, test } from '@playwright/test';

/** Print buttons print a PDF from a hidden same-origin frame; the admin CSP must allow it. */
test('a PDF loads in the print frame without a CSP violation', async ({ page }) => {
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill('mominulfed@gmail.com');
    await page.locator('input[type="password"]').fill('password');
    await page.locator('button[type="submit"]').click();
    await expect(page).not.toHaveURL(/\/login/);
    await page.goto('/admin/settings/invoice');
    await expect(page.getByRole('heading', { name: 'Paper and language' })).toBeVisible();

    const result = await page.evaluate(async () => {
        const violations: string[] = [];
        document.addEventListener('securitypolicyviolation', (e) => violations.push(`${e.violatedDirective} ${e.blockedURI}`));
        const response = await fetch('/api/v1/admin/settings/print/invoice-preview', { credentials: 'same-origin', headers: { Accept: 'application/pdf' } });
        const url = URL.createObjectURL(await response.blob());
        const frame = document.createElement('iframe');
        frame.style.cssText = 'position:fixed;right:0;bottom:0;width:1px;height:1px;border:0;opacity:0;pointer-events:none';
        const loaded = new Promise<void>((resolve) => (frame.onload = () => resolve()));
        frame.src = url;
        document.body.appendChild(frame);
        // Headless Chromium in CI may have no PDF viewer, so `load` can be late or never; the
        // CSP check is what matters, and a blocked frame reports its violation at once.
        await Promise.race([loaded, new Promise((r) => setTimeout(r, 5000))]);
        await new Promise((r) => setTimeout(r, 500));
        return { status: response.status, violations };
    });
    console.log(JSON.stringify(result));
    expect(result.status).toBe(200);
    expect(result.violations).toEqual([]);
});

import { chromium } from '@playwright/test';
const out = process.argv[2];
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 1300 } });
await page.goto('http://127.0.0.1:8135/admin/login');
await page.getByLabel('Email').fill('owner@toymart.test');
await page.getByLabel('Password').fill('password');
await page.getByRole('button', { name: /sign in/i }).click();
await page.waitForURL(/\/admin\/?$/);
for (const [name, path] of [['coupons', '/admin/marketing/coupons'], ['coupon-new', '/admin/marketing/coupons/new'], ['coupon-edit', '/admin/marketing/coupons/1'], ['generate', '/admin/marketing/coupons/generate'], ['promotions', '/admin/marketing/promotions'], ['flash-edit', '/admin/marketing/flash-sales/1'], ['promo-new', '/admin/marketing/promotions/new'], ['abandoned', '/admin/marketing/abandoned-carts']]) {
  await page.goto('http://127.0.0.1:8135' + path);
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${out}/${name}.png`, fullPage: true });
}
await browser.close();

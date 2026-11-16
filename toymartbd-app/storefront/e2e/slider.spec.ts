import { expect, test, type Page } from "@playwright/test";

// The product-rail slider (Figma RailSliderControls 434:12311 under Home Best sellers 127:333 /
// New arrivals 127:394; components/carousel/SwiperCarousel). Below 1024 with JavaScript the row
// becomes a Swiper track with dots and prev/next; from 1024, and without JavaScript, it stays the
// native row / 4-up grid. Swiper is fetched as the rail nears the viewport, so each test scrolls
// to the rail first. SHOTS_DIR=<dir> saves phone screenshots for the Figma check.

const shots = process.env.SHOTS_DIR;

async function noPageScroll(page: Page) {
    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth
    );
    expect(overflow).toBeLessThanOrEqual(0);
}

test.describe("with JavaScript at 390", () => {
    test.use({
        javaScriptEnabled: true,
        viewport: { width: 390, height: 844 },
    });

    test("best sellers and new arrivals become sliders with working controls", async ({
        page,
    }) => {
        await page.goto("/");
        for (const id of ["home-best", "home-new"]) {
            const rail = page.locator(`section[aria-labelledby="${id}"]`);
            await rail.scrollIntoViewIfNeeded();
            const track = rail.locator(".tm-swiper-track");
            const next = rail.getByRole("button", { name: "Next products" });
            await expect(next).toBeVisible();
            await expect(
                rail.getByRole("button", { name: "Previous products" })
            ).toBeVisible();
            await expect(rail.locator(".tm-dot").first()).toBeVisible();
            await expect(rail.locator(".tm-dot-active")).toHaveCount(1);

            const before = await track.evaluate(
                (el) => getComputedStyle(el).transform
            );
            await next.click();
            await expect
                .poll(() =>
                    track.evaluate((el) => getComputedStyle(el).transform)
                )
                .not.toBe(before);
            if (shots)
                await rail.screenshot({ path: `${shots}/${id}-390.png` });
        }
        await noPageScroll(page);
    });

    test("Bangla rail controls are labelled in Bangla", async ({ page }) => {
        await page.goto("/bn");
        const rail = page.locator('section[aria-labelledby="home-best"]');
        await rail.scrollIntoViewIfNeeded(); // Swiper loads as the rail nears the viewport
        await expect(
            rail.getByRole("button", { name: "পরের পণ্য" })
        ).toBeVisible();
        await noPageScroll(page);
    });
});

test.describe("from 1024", () => {
    test.use({
        javaScriptEnabled: true,
        viewport: { width: 1440, height: 900 },
    });

    test("stays the 4-up grid without slider controls", async ({ page }) => {
        await page.goto("/");
        const rail = page.locator('section[aria-labelledby="home-best"]');
        await expect(rail.locator("ul").first()).toHaveCSS("display", "grid");
        await expect(
            rail.getByRole("button", { name: "Next products" })
        ).toHaveCount(0);
    });
});

test.describe("without JavaScript at 390", () => {
    test.use({
        javaScriptEnabled: false,
        viewport: { width: 390, height: 844 },
    });

    test("is the native swipe row, every card reachable, no controls", async ({
        page,
    }) => {
        await page.goto("/");
        const rail = page.locator('section[aria-labelledby="home-best"]');
        await expect(rail.locator("ul").first()).toHaveCSS(
            "overflow-x",
            "auto"
        );
        await expect(
            rail.getByRole("button", { name: "Next products" })
        ).toHaveCount(0);
        await noPageScroll(page);
    });
});

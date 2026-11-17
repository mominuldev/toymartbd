import {
    act,
    cleanup,
    fireEvent,
    screen,
    within,
} from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { mockApi, renderApp, viewer } from "@/test/renderApp";
import type { Me } from "@/features/auth/api";
import {
    LIVE_REFRESH_MS,
    liveRefetchInterval,
    type LiveAnalytics,
} from "./api";

afterEach(() => {
    vi.useRealTimers();
    cleanup();
    localStorage.clear();
});

const reader: Me = {
    ...viewer,
    permissions: ["dashboard.view", "reports.view"],
};

/** 08:31 UTC = 2:31 pm Dhaka. */
function report(overrides: Partial<LiveAnalytics> = {}): LiveAnalytics {
    const hourly = Array.from({ length: 24 }, (_, hour) => ({
        hour,
        orders: hour === 9 ? 2 : hour === 14 ? 1 : 0,
        value: hour === 9 ? "3500.00" : hour === 14 ? "1520.00" : "0.00",
    }));
    return {
        generated_at: "2026-11-17T08:31:00Z",
        today: "2026-11-17",
        window_minutes: 30,
        tracking: {
            consent_gated: true,
            consent_mode: "opt_in",
            collecting: true,
            sessions_need_ga4: true,
            ga4_enabled: true,
        },
        now: { active_sessions: 14, checkout_sessions: 3 },
        orders_today: {
            count: 9,
            value: "28400.00",
            same_time_yesterday: 7,
            difference: 2,
        },
        last_order: {
            number: "TMB-26-00141",
            amount: "1520.00",
            district: "Chattogram",
            district_bn: "চট্টগ্রাম",
            product_name: "Plush Teddy Bear",
            product_name_bn: "টেডি বিয়ার",
            at: "2026-11-17T08:27:00Z",
            minutes_ago: 4,
        },
        feed: [
            {
                id: "order-141",
                kind: "order",
                at: "2026-11-17T08:27:00Z",
                minutes_ago: 4,
                order_number: "TMB-26-00141",
                amount: "1520.00",
                district: "Chattogram",
                district_bn: "চট্টগ্রাম",
                product_name: null,
                product_name_bn: null,
                items: null,
            },
            {
                id: "event-9",
                kind: "add_to_cart",
                at: "2026-11-17T08:26:00Z",
                minutes_ago: 5,
                order_number: null,
                amount: "1450.00",
                district: null,
                district_bn: null,
                product_name: "Kids Tricycle with Push Handle",
                product_name_bn: "বাচ্চাদের ট্রাইসাইকেল",
                items: 1,
            },
            {
                id: "event-8",
                kind: "begin_checkout",
                at: "2026-11-17T08:24:00Z",
                minutes_ago: 7,
                order_number: null,
                amount: "3890.00",
                district: null,
                district_bn: null,
                product_name: "Plush Teddy Bear",
                product_name_bn: null,
                items: 2,
            },
            {
                id: "event-7",
                kind: "view_item",
                at: "2026-11-17T08:23:00Z",
                minutes_ago: 8,
                order_number: null,
                amount: null,
                district: null,
                district_bn: null,
                product_name: "Magnetic Building Blocks 64 pc",
                product_name_bn: null,
                items: 1,
            },
        ],
        viewing: {
            pages_available: false,
            pages_reason: "no_page_views",
            products: [
                {
                    key: "product:1",
                    name: "Kids Tricycle with Push Handle",
                    name_bn: "বাচ্চাদের ট্রাইসাইকেল",
                    sessions: 4,
                },
            ],
        },
        hourly,
        ...overrides,
    };
}

function api(body: LiveAnalytics = report()) {
    return mockApi((url) => {
        if (url.endsWith("/auth/me")) return { status: 200, body: reader };
        if (url.includes("/analytics/live")) return { status: 200, body };
        if (url.includes("/inventory/low-stock-count"))
            return { status: 200, body: { location_id: 1, count: 0 } };
        return { status: 404 };
    });
}

const liveCalls = (calls: ReturnType<typeof mockApi>) =>
    calls.mock.calls.filter(([url]) => String(url).includes("/analytics/live"))
        .length;

describe("live analytics", () => {
    it("leads with plain sentences built only from the server’s figures", async () => {
        const calls = api();
        renderApp("/analytics/live");
        const words = await screen.findByRole("region", {
            name: /In plain words/,
        });
        expect(
            within(words).getByText(
                "14 shoppers are on the shop now; 3 of them are at checkout."
            )
        ).toBeTruthy();
        expect(
            within(words).getByText(
                "9 orders today so far for ৳28,400 — 2 more than this time yesterday."
            )
        ).toBeTruthy();
        expect(
            within(words).getByText(
                "The last order came 4 minutes ago from Chattogram (Plush Teddy Bear)."
            )
        ).toBeTruthy();
        expect(screen.getByText("Live · updated 2:31 pm")).toBeTruthy();
        expect(screen.getByText("৳28,400 placed")).toBeTruthy();
        expect(screen.getByText("4 min")).toBeTruthy();
        expect(
            calls.mock.calls.some(([url]) =>
                String(url).endsWith("/analytics/live")
            )
        ).toBe(true);
    });

    it("lists the feed newest first with no shopper details, and the products being viewed", async () => {
        api();
        renderApp("/analytics/live");
        const feed = await screen.findByRole("list", {
            name: "What is happening",
        });
        const items = within(feed).getAllByRole("listitem");
        expect(items.map((li) => li.textContent)).toEqual([
            "Order placed · TMB-26-00141 · ৳1,520 · Chattogram4 min ago",
            "Added to cart · Kids Tricycle with Push Handle5 min ago",
            "Started checkout · 2 items · ৳3,8907 min ago",
            "Viewing · Magnetic Building Blocks 64 pc8 min ago",
        ]);
        expect(screen.getByText("4 shoppers")).toBeTruthy();
        expect(screen.getByText(/Pages are not counted/)).toBeTruthy();
        expect(
            screen.getByText(
                /cover shoppers who allowed tracking\. Orders cover every order\./
            )
        ).toBeTruthy();
        expect(screen.getByLabelText("9 am: 2 orders")).toBeTruthy();
    });

    it("says so honestly when nothing has happened and tracking is off", async () => {
        api(
            report({
                tracking: {
                    consent_gated: true,
                    consent_mode: "opt_in",
                    collecting: false,
                    sessions_need_ga4: true,
                    ga4_enabled: false,
                },
                now: { active_sessions: 0, checkout_sessions: 0 },
                orders_today: {
                    count: 0,
                    value: "0.00",
                    same_time_yesterday: 3,
                    difference: -3,
                },
                last_order: null,
                feed: [],
                viewing: {
                    pages_available: false,
                    pages_reason: "no_page_views",
                    products: [],
                },
            })
        );
        renderApp("/analytics/live");
        expect(await screen.findByText(/Nothing yet\./)).toBeTruthy();
        expect(
            screen.getByText(
                "Shopper counts are off: no tracking is switched on."
            )
        ).toBeTruthy();
        expect(
            screen.getByText("No orders yet today — 3 by this time yesterday.")
        ).toBeTruthy();
        expect(
            screen.getByText(
                /Tracking is switched off, so only orders show here/
            )
        ).toBeTruthy();
        expect(screen.getByText("No orders yet")).toBeTruthy();
    });

    it("refreshes every minute and stops when paused", async () => {
        expect(liveRefetchInterval(false)).toBe(LIVE_REFRESH_MS);
        expect(liveRefetchInterval(true)).toBe(false);

        vi.useFakeTimers({ shouldAdvanceTime: true });
        const calls = api();
        renderApp("/analytics/live");
        await screen.findByText("Live · updated 2:31 pm");
        const before = liveCalls(calls);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(LIVE_REFRESH_MS + 100);
        });
        const afterOneMinute = liveCalls(calls);
        expect(afterOneMinute).toBeGreaterThan(before);

        fireEvent.click(screen.getByRole("button", { name: "Pause" }));
        expect(screen.getByText("Paused · updated 2:31 pm")).toBeTruthy();
        expect(screen.getByRole("button", { name: "Resume" })).toBeTruthy();
        await act(async () => {
            await vi.advanceTimersByTimeAsync(LIVE_REFRESH_MS * 3);
        });
        expect(liveCalls(calls)).toBe(afterOneMinute);
    });

    it("renders in Bangla with Western digits", async () => {
        api();
        renderApp("/analytics/live", { locale: "bn" });
        expect(
            await screen.findByText(
                "এখন দোকানে 14 জন ক্রেতা আছেন; তাঁদের 3 জন চেকআউটে।"
            )
        ).toBeTruthy();
        expect(screen.getByText(/চট্টগ্রাম থেকে \(টেডি বিয়ার\)/)).toBeTruthy();
        expect(
            screen.getAllByText("বাচ্চাদের ট্রাইসাইকেল").length
        ).toBeGreaterThan(0);
        expect(document.body.textContent ?? "").not.toMatch(/[০-৯]/);
    });
});

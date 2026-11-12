import {
    cleanup,
    fireEvent,
    screen,
    waitFor,
    within,
} from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { renderApp } from "@/test/renderApp";
import type { Me } from "@/features/auth/api";
import {
    branchRows,
    branchSummary,
    inventoryApi,
    inventoryStaff,
    sentBody,
} from "./fixtures";

/* P5-INV: transfers, the adjustment approval queue, stock alerts and the ledger's reason filter. */

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const urls = (calls: ReturnType<typeof inventoryApi>) =>
    calls.mock.calls.map(([url]) => String(url));
const posts = (calls: ReturnType<typeof inventoryApi>) =>
    calls.mock.calls
        .filter(
            ([, init]) => (init as RequestInit | undefined)?.method === "POST",
        )
        .map(([url, init]) => ({
            url: String(url),
            init: init as RequestInit,
        }));

const mover: Me = {
    ...inventoryStaff,
    permissions: [...inventoryStaff.permissions, "inventory.transfer"],
};
const manager: Me = {
    ...inventoryStaff,
    name: "Manager Mitu",
    roles: ["manager"],
    permissions: [...mover.permissions, "inventory.approve_adjustments"],
};

const branches = (url: string) => {
    if (url.includes("/inventory/summary"))
        return { status: 200, body: branchSummary };
    if (url.includes("/inventory/stock"))
        return {
            status: 200,
            body: {
                data: branchRows,
                links: { next: null },
                meta: { next_cursor: null },
            },
        };
    return undefined;
};

describe("stock transfer", () => {
    it("opens Transfer N from the branch that has it into this one and posts it with an Idempotency-Key", async () => {
        const calls = inventoryApi(mover, branches);
        renderApp("/inventory");
        const table = await screen.findByRole("table");
        expect(
            (
                screen.getByRole("button", {
                    name: /Transfer stock/,
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(false);

        fireEvent.click(
            within(table).getByRole("button", { name: "Transfer 4" }),
        );
        const dialog = await screen.findByRole("dialog", {
            name: "Transfer stock",
        });
        expect(
            (within(dialog).getByLabelText(/Units to move/) as HTMLInputElement)
                .value,
        ).toBe("4");
        await within(dialog).findByText(/On hand/);

        fireEvent.click(
            within(dialog).getByRole("button", { name: "Transfer" }),
        );
        await waitFor(() =>
            expect(
                posts(calls).some((p) =>
                    p.url.endsWith("/inventory/transfers"),
                ),
            ).toBe(true),
        );
        const sent = sentBody(calls)!;
        expect(sent.body).toMatchObject({
            from_location_id: 2,
            to_location_id: 1,
            lines: [{ quantity: 4 }],
        });
        expect(sent.headers["Idempotency-Key"]).toBeTruthy();
        expect(
            await screen.findByText("Transfer TRF-2026-0003 done"),
        ).toBeTruthy();
    });

    it("shows a notice linking to the approval queue and the drift alerts", async () => {
        inventoryApi(mover);
        renderApp("/inventory");
        await screen.findByRole("table");

        expect(
            await screen.findByText("1 adjustments waiting for approval"),
        ).toBeTruthy();
        expect(
            await screen.findByText("1 stock ledger drift alerts open"),
        ).toBeTruthy();
        expect(
            screen
                .getAllByRole("link", { name: "Review" })
                .map((a) => a.getAttribute("href")),
        ).toEqual(["/inventory/adjustments", "/inventory/alerts"]);
    });
});

describe("adjustment approval queue", () => {
    it("lists pending adjustments and approves one with an Idempotency-Key", async () => {
        const calls = inventoryApi(manager);
        renderApp("/inventory/adjustments");
        const table = await screen.findByRole("table");
        expect(within(table).getByText("ADJ-2026-0013")).toBeTruthy();
        expect(within(table).getByText("Pending approval")).toBeTruthy();
        expect(
            urls(calls).some((u) => u.includes("filter%5Bstatus%5D=pending")),
        ).toBe(true);

        fireEvent.click(within(table).getByRole("button", { name: "Approve" }));
        const dialog = await screen.findByRole("dialog", {
            name: "Approve ADJ-2026-0013?",
        });
        fireEvent.click(
            within(dialog).getByRole("button", { name: "Approve" }),
        );

        await waitFor(() =>
            expect(
                posts(calls).some((p) =>
                    p.url.endsWith("/inventory/adjustments/13/approve"),
                ),
            ).toBe(true),
        );
        const approve = posts(calls).find((p) => p.url.endsWith("/approve"))!;
        expect(
            (approve.init.headers as Record<string, string>)["Idempotency-Key"],
        ).toBeTruthy();
        expect(
            await screen.findByText("ADJ-2026-0013 approved — stock moved"),
        ).toBeTruthy();
    });

    it("requires a reason to reject, then sends it", async () => {
        const calls = inventoryApi(manager);
        renderApp("/inventory/adjustments");
        const table = await screen.findByRole("table");

        fireEvent.click(within(table).getByRole("button", { name: "Reject" }));
        const dialog = await screen.findByRole("dialog", {
            name: "Reject ADJ-2026-0013?",
        });
        fireEvent.click(within(dialog).getByRole("button", { name: "Reject" }));
        expect(posts(calls).some((p) => p.url.endsWith("/reject"))).toBe(false);

        fireEvent.change(
            within(dialog).getByLabelText(/Reason for rejecting/),
            { target: { value: "Wrong lot counted" } },
        );
        fireEvent.click(within(dialog).getByRole("button", { name: "Reject" }));
        await waitFor(() =>
            expect(
                posts(calls).some((p) =>
                    p.url.endsWith("/inventory/adjustments/13/reject"),
                ),
            ).toBe(true),
        );
        expect(
            JSON.parse(
                String(
                    posts(calls).find((p) => p.url.endsWith("/reject"))!.init
                        .body,
                ),
            ),
        ).toEqual({ note: "Wrong lot counted" });
    });

    it("offers no decision to staff without the approval permission", async () => {
        inventoryApi(inventoryStaff);
        renderApp("/inventory/adjustments");
        const table = await screen.findByRole("table");

        expect(
            within(table).queryByRole("button", { name: "Approve" }),
        ).toBeNull();
        expect(
            within(table).queryByRole("button", { name: "Reject" }),
        ).toBeNull();
    });
});

describe("stock alerts", () => {
    it("shows drift figures from the server and acknowledges with a note", async () => {
        const calls = inventoryApi(manager);
        renderApp("/inventory/alerts");
        const table = await screen.findByRole("table");
        expect(
            within(table).getByText("Ledger 12 · level 9 (−3)"),
        ).toBeTruthy();

        fireEvent.click(
            within(table).getByRole("button", { name: "Acknowledge" }),
        );
        const dialog = await screen.findByRole("dialog", {
            name: "Acknowledge this alert?",
        });
        fireEvent.change(within(dialog).getByLabelText(/What you found/), {
            target: { value: "Fixed by ADJ-2026-0042" },
        });
        fireEvent.click(
            within(dialog).getByRole("button", { name: "Acknowledge" }),
        );

        await waitFor(() =>
            expect(
                posts(calls).some((p) =>
                    p.url.endsWith("/inventory/alerts/7/acknowledge"),
                ),
            ).toBe(true),
        );
        expect(await screen.findByText("Alert acknowledged")).toBeTruthy();
    });

    it("renders in Bangla", async () => {
        inventoryApi(manager);
        renderApp("/inventory/alerts", { locale: "bn" });
        const table = await screen.findByRole("table");
        expect(within(table).getByText("লেজার 12 · লেভেল 9 (−3)")).toBeTruthy();
    });
});

describe("movement ledger reason filter", () => {
    it("asks the server for movements of one adjustment reason", async () => {
        const calls = inventoryApi(inventoryStaff);
        renderApp("/inventory/movements");
        await screen.findByRole("table");

        fireEvent.change(screen.getByLabelText("Adjustment reason"), {
            target: { value: "theft" },
        });
        await waitFor(() =>
            expect(
                urls(calls).some(
                    (u) =>
                        u.includes("/inventory/movements?") &&
                        u.includes("filter%5Breason%5D=theft"),
                ),
            ).toBe(true),
        );
        expect(
            urls(calls).some(
                (u) =>
                    u.includes("/reconciliation") &&
                    u.includes("filter%5Breason%5D"),
            ),
        ).toBe(false);
    });
});

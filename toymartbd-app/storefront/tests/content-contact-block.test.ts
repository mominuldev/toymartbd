import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it, vi } from "vitest";

import { normalizeHome } from "@/lib/content/home-blocks";
import { sampleStoreInfo } from "@/lib/site-config";

// The Contact block (CMS section `contact_info`, 2026-11-16): copy and switches from the block,
// every value from the store's own settings; an unset value is the marked sample, never a link.

const store = vi.hoisted(() => ({ info: null as unknown }));
vi.mock("@/lib/store-info", () => ({ getStoreInfo: async () => store.info }));

const { ContactInfoBlock } = await import(
    "@/components/content/blocks/ContactInfoBlock"
);

const raw = (content: Record<string, unknown>) =>
    normalizeHome({
        version: 1,
        blocks: [
            {
                id: "c1",
                type: "contact_info",
                show_mobile: true,
                show_desktop: true,
                content,
            },
        ],
    }).blocks[0];

describe("contact_info block", () => {
    it("parses the switches and drops an unsafe map link", () => {
        const block = raw({
            title: "Talk to us",
            sub: null,
            hours: "9 to 9",
            map_href: "javascript:alert(1)",
            show_email: false,
            show_address: true,
        });
        expect(block).toMatchObject({
            type: "contact_info",
            content: {
                title: "Talk to us",
                hours: "9 to 9",
                map_href: null,
                channels: ["hotline", "whatsapp", "messenger", "address"],
            },
        });
        expect(raw({ title: "" })).toBeUndefined();
    });

    it("renders the store values as links, and samples as marked plain text", async () => {
        store.info = {
            ...sampleStoreInfo,
            contact: {
                ...sampleStoreInfo.contact,
                hotline: {
                    display: "09612-000111",
                    href: "tel:+8809612000111",
                    placeholder: false,
                },
                messenger: null,
            },
            address: {
                en: "House 1, Road 2, Dhanmondi, Dhaka",
                bn: "বাড়ি 1, রোড 2, ধানমন্ডি, ঢাকা",
            },
        };
        const content = {
            title: "Talk to us",
            sub: "We answer every day.",
            hours: "Saturday to Thursday",
            map_href: "https://maps.google.com/?q=toymart",
            channels: [
                "hotline",
                "whatsapp",
                "messenger",
                "email",
                "address",
            ] as const,
        };
        const html = renderToStaticMarkup(
            await ContactInfoBlock({
                id: "c1",
                content: { ...content, channels: [...content.channels] },
                locale: "en",
            })
        );

        expect(html).toContain('href="tel:+8809612000111"');
        expect(html).toContain('data-placeholder="owner-to-confirm"'); // the sample WhatsApp / email
        expect(html).not.toContain("wa.me");
        expect(html).not.toContain('data-contact="messenger"'); // not set: left out
        expect(html).toContain("House 1, Road 2, Dhanmondi, Dhaka");
        expect(html).toContain('href="https://maps.google.com/?q=toymart"');
        expect(html).toContain('data-contact="hours"');

        const bn = renderToStaticMarkup(
            await ContactInfoBlock({
                id: "c1",
                content: { ...content, channels: ["address"] },
                locale: "bn",
            })
        );
        expect(bn).toContain("বাড়ি 1, রোড 2, ধানমন্ডি, ঢাকা");
        expect(bn).toContain("lang-bn");
    });
});

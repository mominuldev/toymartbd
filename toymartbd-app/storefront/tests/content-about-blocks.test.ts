import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";

import { HomeBlocks } from "@/components/content/HomeBlocks";
import { normalizeHome } from "@/lib/content/home-blocks";

// The About blocks (2026-11-16, About us 170:10502): story hero, feature tiles, numbered cards and
// the call-to-action band — untrusted API bodies are checked, and a leading story hero is the H1.

const photo = (n: number) => ({
    url: `https://cdn.test/media/${n}/1280.webp`,
    srcset_webp: null,
    srcset_avif: null,
    width: 1280,
    height: 1280,
    alt: "A toy",
});
const parse = (type: string, content: Record<string, unknown>) =>
    normalizeHome({
        version: 1,
        blocks: [{ id: "b1", type, show_mobile: true, show_desktop: true, content }],
    }).blocks;
const html = (blocks: ReturnType<typeof parse>, storyOpensPage = false) =>
    renderToStaticMarkup(
        createElement(HomeBlocks, { blocks, locale: "en", hotline: "09610-123456", pageHeading: false, storyOpensPage })
    );

describe("story_hero", () => {
    const content = { overline: "Our story", title: "About Toy Mart BD", lead: "We started…", body: null, images: [photo(1), photo(2), photo(3), photo(4), { url: "javascript:x" }], badge: "Ages", badge_sub: "0–12", tone: "neon" };

    it("keeps at most three safe photos and falls back to the sunrise tone", () => {
        const [block] = parse("story_hero", content);
        expect(block?.type).toBe("story_hero");
        if (block?.type !== "story_hero") return;
        expect(block.content.images.map((i) => i.url)).toEqual([photo(1).url, photo(2).url, photo(3).url]);
        expect(block.content.tone).toBe("sunrise");
    });

    it("is dropped without a title or a lead", () => {
        expect(parse("story_hero", { ...content, lead: "" })).toEqual([]);
        expect(parse("story_hero", { ...content, title: null })).toEqual([]);
    });

    it("is the page's H1 only when it opens a page", () => {
        const blocks = parse("story_hero", content);
        expect(html(blocks, true)).toContain("<h1");
        expect(html(blocks, false)).not.toContain("<h1");
        expect(html(blocks, true)).toContain("0–12");
    });

    it("drops the collage without photos", () => {
        expect(html(parse("story_hero", { ...content, images: [] }))).not.toContain("aria-hidden=\"true\" class=\"relative");
    });
});

describe("feature_tiles", () => {
    it("caps at four tiles, replaces an unknown icon and drops untitled tiles", () => {
        const items = [
            { icon: "rocket", title: "One" },
            { icon: "gift", title: "" },
            { icon: "map_pin", title: "Two" },
            { icon: "truck", title: "Three" },
            { icon: "money", title: "Four" },
            { icon: "chat", title: "Five" },
        ];
        const [block] = parse("feature_tiles", { title: null, items });
        if (block?.type !== "feature_tiles") throw new Error("not parsed");
        expect(block.content.items.map((i) => i.title)).toEqual(["One", "Two", "Three", "Four"]);
        expect(block.content.items[0]?.icon).toBe("check_circle");
        // No section title: each tile title is an h2.
        expect(html([block]).match(/<h2/g)).toHaveLength(4);
    });
});

describe("numbered_cards", () => {
    it("numbers the cards 01, 02… with Western digits", () => {
        const blocks = parse("numbered_cards", { title: "What we believe", items: [{ title: "Play" }, { title: "Truth", body: "Real photos." }] });
        const out = html(blocks);
        expect(out).toContain(">01<");
        expect(out).toContain(">02<");
    });
});

describe("cta_band", () => {
    it("shows only buttons with a label and a safe link; an https link opens a new tab", () => {
        const [block] = parse("cta_band", {
            title: "Come and play at our shop",
            primary_label: "Get directions",
            primary_href: "https://maps.example/x",
            primary_icon: "map_pin",
            secondary_label: "Contact us",
            secondary_href: "javascript:alert(1)",
        });
        if (block?.type !== "cta_band") throw new Error("not parsed");
        expect(block.content.primary?.icon).toBe("map_pin");
        expect(block.content.secondary).toBeNull();
        expect(block.content.tone).toBe("candy");
        expect(html([block])).toContain('target="_blank"');
    });
});

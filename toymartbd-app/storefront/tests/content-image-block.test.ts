import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";

import { HomeBlocks } from "@/components/content/HomeBlocks";
import { normalizeHome } from "@/lib/content/home-blocks";

// The Image block (CMS section `image`, 2026-11-16): never rendered without an image, links only
// to safe targets, text width by default.

const image = {
    url: "https://cdn.test/media/9/1280.webp",
    srcset_webp: "https://cdn.test/media/9/800.webp 800w",
    srcset_avif: null,
    width: 1280,
    height: 720,
    alt: "Children building a tower",
};
const parse = (content: Record<string, unknown>) =>
    normalizeHome({
        version: 1,
        blocks: [
            {
                id: "i1",
                type: "image",
                show_mobile: true,
                show_desktop: true,
                content,
            },
        ],
    }).blocks;

describe("image block", () => {
    it("is dropped without an image and keeps only a safe link", () => {
        expect(parse({ image: null, caption: "x" })).toEqual([]);
        expect(parse({ image, href: "javascript:alert(1)" })[0]).toMatchObject({
            type: "image",
            content: { href: null, width: "content" },
        });
        expect(
            parse({ image, href: "/toys?age=24-48", width: "wide" })[0]
        ).toMatchObject({
            content: { href: "/toys?age=24-48", width: "wide" },
        });
    });

    it("renders a figure with alt text, caption, sized picture and a locale-prefixed link", () => {
        const blocks = parse({
            image,
            caption: "Our Dhanmondi play corner",
            href: "/toys",
        });
        const html = renderToStaticMarkup(
            createElement(HomeBlocks, {
                blocks,
                locale: "bn",
                hotline: "09610-123456",
            })
        );
        expect(html).toContain("<figure");
        expect(html).toContain('alt="Children building a tower"');
        expect(html).toContain('width="1280"');
        expect(html).toContain("<figcaption");
        expect(html).toContain('href="/bn/toys"');
        expect(html).toContain("max-w-prose");
    });
});

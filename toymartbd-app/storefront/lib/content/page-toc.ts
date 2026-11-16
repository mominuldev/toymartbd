import type { HomeBlock } from './home-blocks';
import { splitSections, type PageSection } from './types';
import { descriptionContext, sanitizeDescription } from '@/lib/product/description';

// "On this page" for a static page built from sections (Figma Privacy 166:9992, Terms 173:11315,
// FAQ 171:10747). Pure and render-side: the entries come from the sections themselves — every h2
// of every rich-text section (numbered 01, 02… across the page, the policy look) and every FAQ
// section (its title and how many questions it holds, the FAQ topic list). A page whose sections
// give fewer than two entries (Contact, About) has no contents list and keeps the single column.

export interface TocEntry {
    id: string;
    label: string;
    /** Rich-text headings are numbered across the page; FAQ groups show their question count. */
    number: number | null;
    count: number | null;
}

/** The rich-text HTML as it is rendered: the API's sanitised HTML filtered once more (defence in depth). */
export function cleanRichText(html: string): string | null {
    return sanitizeDescription(html, descriptionContext(process.env.API_URL, []));
}

/** One rich-text section split at its h2s, ids continuing the page-wide count (`section-{n}`). */
export function numberedSections(html: string, start: number): { intro: string; sections: Array<PageSection & { number: number }> } {
    const { intro, sections } = splitSections(html);
    return { intro, sections: sections.map((s, i) => ({ ...s, id: `section-${start + i}`, number: start + i })) };
}

/** The anchor of an FAQ section (also its heading's `aria-labelledby` target). */
export const faqAnchor = (blockId: string): string => `cms-${blockId}-title`;

/**
 * The contents list, and for each rich-text section the number its first heading takes (by block
 * index), so the article and the list agree.
 */
export function pageToc(blocks: HomeBlock[]): { entries: TocEntry[]; starts: Map<number, number> } {
    const entries: TocEntry[] = [];
    const starts = new Map<number, number>();
    let next = 1;
    blocks.forEach((block, i) => {
        if (block.type === 'rich_text') {
            const html = cleanRichText(block.content.html);
            if (!html) return;
            starts.set(i, next);
            for (const s of numberedSections(html, next).sections) entries.push({ id: s.id, label: s.heading, number: s.number, count: null });
            next += splitSections(html).sections.length;
        } else if (block.type === 'faq') {
            entries.push({ id: faqAnchor(block.id || String(i)), label: block.content.title, number: null, count: block.content.items.length });
        }
    });
    return { entries, starts };
}

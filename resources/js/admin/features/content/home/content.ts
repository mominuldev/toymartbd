import type { MessageKey } from '@/i18n/messages';
import type { BlockType, CmsBlock } from './api';

/*
 * Per-type views of a block's `content` (mirrors App\Domain\Content\Blocks\Schemas). Values are
 * read defensively — the server is the only validator — and written back whole on save.
 */

export type Content = Record<string, unknown>;

export const BLOCK_TYPES: readonly BlockType[] = [
    'hero', 'category_tiles', 'age_tiles', 'product_rail', 'flash_sale', 'brand_strip', 'usp_band', 'promo_split', 'testimonials', 'blog_teaser', 'rich_text', 'faq',
];

export const typeLabel = (type: BlockType): MessageKey => `homeBuilder.type.${type}` as MessageKey;

export const str = (v: unknown): string => (typeof v === 'string' ? v : '');
export const num = (v: unknown, fallback: number): number => (typeof v === 'number' ? v : fallback);
export const bool = (v: unknown, fallback: boolean): boolean => (typeof v === 'boolean' ? v : fallback);
export const ids = (v: unknown): number[] => (Array.isArray(v) ? v.filter((i): i is number => typeof i === 'number') : []);
export const rows = (v: unknown): Content[] => (Array.isArray(v) ? v.filter((r): r is Content => typeof r === 'object' && r !== null && !Array.isArray(r)) : []);

/** The block's title or headline, for the section list. */
export function blockTitle(block: CmsBlock): string {
    const c = block.content as Content;
    if (block.type === 'hero') return str(rows(c.slides)[0]?.headline);
    if (block.type === 'promo_split') return str(c.headline);
    if (block.type === 'rich_text') return str(c.html).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 60);
    return str(c.title);
}

/** Schema fields that are Bangla copy (`x_bn`). */
export const isBanglaKey = (key: string): boolean => key.endsWith('_bn');

/** Server errors for a field path under `content.` (`slides.0.headline`). */
export function contentError(errors: Record<string, string>, path: string): string | undefined {
    return errors[`content.${path}`];
}

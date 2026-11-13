import { formatDhakaDayMonth } from '@/features/content/shared/dhaka';
import type { MessageKey } from '@/i18n/messages';
import type { CmsBlock } from './api';
import { bool, ids, num, rows, str, type Content } from './content';

type T = (key: MessageKey, vars?: Record<string, string | number>) => string;

/** The PageBuilderSection second line: "Best sellers · 8 products · auto", "scheduled 15 Jun" … */
export function blockSummary(block: CmsBlock, t: T, locale: string, now: number = Date.now()): string {
    const c = block.content as Content;
    const parts: string[] = [];

    switch (block.type) {
        case 'hero':
            parts.push(t('homeBuilder.summary.slides', { count: rows(c.slides).length }));
            break;
        case 'category_tiles':
            parts.push(str(c.source) === 'manual' ? t('homeBuilder.summary.categoriesManual', { count: ids(c.category_ids).length }) : t('homeBuilder.summary.categoriesAuto', { count: num(c.limit, 8) }));
            break;
        case 'age_tiles':
            parts.push(t('homeBuilder.summary.ageBands'));
            break;
        case 'product_rail':
            parts.push(t('homeBuilder.summary.products', { rule: t(`homeBuilder.f.rule.${str(c.rule) || 'best_sellers'}` as MessageKey, { days: num(c.window_days, 30) }), count: num(c.limit, 8) }));
            break;
        case 'brand_strip':
            parts.push(ids(c.brand_ids).length ? t('homeBuilder.summary.brands', { count: ids(c.brand_ids).length }) : t('homeBuilder.summary.allBrands'));
            break;
        case 'usp_band':
        case 'faq':
            parts.push(t('homeBuilder.summary.items', { count: rows(c.items).length }));
            break;
        case 'flash_sale':
            parts.push(t('homeBuilder.summary.flashSale', { count: num(c.limit, 8) }));
            break;
        case 'testimonials':
        case 'blog_teaser':
            parts.push(t('homeBuilder.summary.latest', { count: num(c.limit, block.type === 'blog_teaser' ? 3 : 6) }));
            break;
        default:
            parts.push(t('homeBuilder.summary.text'));
    }

    if (!bool(block.is_enabled, true)) {
        parts.push(t('homeBuilder.summary.off'));
    } else if (block.ends_at && Date.parse(block.ends_at) <= now) {
        parts.push(t('homeBuilder.summary.ended', { date: formatDhakaDayMonth(block.ends_at, locale) }));
    } else if (block.starts_at && Date.parse(block.starts_at) > now) {
        parts.push(t('homeBuilder.summary.scheduled', { date: formatDhakaDayMonth(block.starts_at, locale) }));
    } else if (block.ends_at) {
        parts.push(t('homeBuilder.summary.until', { date: formatDhakaDayMonth(block.ends_at, locale) }));
    }

    return parts.join(' · ');
}

/** Hidden on the live page right now: switched off, or outside its schedule window. */
export function isDormant(block: CmsBlock, now: number = Date.now()): boolean {
    return !block.is_enabled || (block.ends_at !== null && Date.parse(block.ends_at) <= now) || (block.starts_at !== null && Date.parse(block.starts_at) > now);
}
